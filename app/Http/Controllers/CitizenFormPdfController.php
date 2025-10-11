<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use mikehaertl\pdftk\Pdf;

class CitizenFormPdfController extends Controller
{
    public function generate(Request $request)
    {
        $domain = $this->readDomain();
        if (empty($domain) || empty($domain['applicant'])) {
            return response()->json(['error' => 'No saved application found.'], 422);
        }

        $tmpFiles = [];
        try {
            $haContent = $this->renderHa($domain['applicant']);
            $haTmp = $this->storeTemp($haContent, 'ha_', $tmpFiles);
            $pdfPaths = [$haTmp];

            $wepData = $domain['wep'] ?? [];
            $candidate = $this->selectWepCandidate($domain);
            if ($candidate) {
                $combinedPerson = array_merge($candidate['member'], $wepData);
                $wepContent = $this->renderWep($domain['applicant'], $combinedPerson, $domain['bg_number'] ?? null);
                if ($wepContent !== null) {
                    $wepTmp = $this->storeTemp($wepContent, 'wep_', $tmpFiles);
                    $pdfPaths[] = $wepTmp;
                }
            }

            $finalContent = $haContent;
            if (count($pdfPaths) > 1) {
                $finalContent = $this->mergePdfs($pdfPaths, $tmpFiles);
            }

            return response($finalContent, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="Buergergeld_HA_WEP.pdf"');
        } finally {
            foreach ($tmpFiles as $tmp) {
                if (is_string($tmp) && file_exists($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    private function renderHa(array $applicant): string
    {
        $fmt = function (?string $iso) {
            if (!$iso) {
                return null;
            }
            $t = strtotime($iso);
            return $t ? date('d.m.Y', $t) : $iso;
        };

        $map = [
            'first_name'     => 'txtfPersonVorname',
            'surname'        => 'txtfPersonNachname',
            'birth_name'     => 'txtfPersonGebName',
            'date_of_birth'  => 'datePersonGebDatum',
            'birth_location' => 'txtfPersonGebOrt',
            'birth_country'  => 'txtfPersonGebLand',
            'nationality'    => 'txtfPersonStaatsangehoerigkeit',
            'address_street' => 'txtfPersonStr',
            'address_no'     => 'txtfPersonHausnr',
            'address_postal' => 'txtfPersonPlz',
            'address_city'   => 'txtfPersonOrt',
            'adress_mailbox' => 'txtfPersonPostfach',
            'phone'          => 'txtfPersonTel',
            'account_holder' => 'txtfKontoinhaber',
            'account_iban'   => 'txtfIBAN',
        ];

        $data = [];
        foreach ($map as $key => $field) {
            if (!array_key_exists($key, $applicant) || $applicant[$key] === null || $applicant[$key] === '') {
                continue;
            }
            $data[$field] = $key === 'date_of_birth' ? $fmt($applicant[$key]) : $applicant[$key];
        }

        $OFF = 'Off';
        $SEL = 'selektiert';

        $data['chbxPersonMaennlich'] = $OFF;
        $data['chbxPersonWeiblich']  = $OFF;
        $data['chbxPersonDivers']    = $OFF;
        $data['chbxPersonKeine']     = $OFF;
        $gender = strtolower((string)($applicant['gender'] ?? ''));
        $genderMap = [
            'male' => 'chbxPersonMaennlich',
            'female' => 'chbxPersonWeiblich',
            'divers' => 'chbxPersonDivers',
            'diverse' => 'chbxPersonDivers',
        ];
        if (isset($genderMap[$gender])) {
            $data[$genderMap[$gender]] = $SEL;
        } else {
            $data['chbxPersonKeine'] = $SEL;
        }

        if (!empty($applicant['no_fixed_residence']) && (string)$applicant['no_fixed_residence'] === '1') {
            $data['chbxWohnsitz'] = $SEL;
        }
        if (isset($applicant['has_bank_account']) && (string)$applicant['has_bank_account'] === '0') {
            $data['chbxKonto'] = $SEL;
        }
        if (!empty($applicant['social_number'])) {
            $data['rbtnPersonSVRVNr'] = 'ja';
            $data['txtfPersonSVRVNr'] = $applicant['social_number'];
        } else {
            $data['rbtnPersonSVRVNr'] = 'nein';
        }
        if (!empty($applicant['nationality']) && strtoupper($applicant['nationality']) !== 'DE' && isset($applicant['has_residence'])) {
            $data['rbtnPersonAufenthaltsgenehm'] = ((string)$applicant['has_residence'] === '1')
                ? 'ja, fuegen Sie die Aufenthaltsgenehmigung bei'
                : 'nein';
        }

        $data['chbxPersonFamStandLedig']         = $OFF;
        $data['chbxPersonFamStandVerheiratet']   = $OFF;
        $data['chbxPersonFamStandVerwitwet']     = $OFF;
        $data['chbxPersonFamStandEingetrLeben']  = $OFF;
        $data['chbxPersonFamStandGetrennt']      = $OFF;
        $data['chbxPersonFamStandGeschieden']    = $OFF;
        $data['chbxPersonFamStandAufgehobLeben'] = $OFF;
        $marital = strtolower((string)($applicant['marital_status'] ?? ''));
        $maritalMap = [
            'single' => 'chbxPersonFamStandLedig',
            'married' => 'chbxPersonFamStandVerheiratet',
            'widowed' => 'chbxPersonFamStandVerwitwet',
            'registered' => 'chbxPersonFamStandEingetrLeben',
            'separated' => 'chbxPersonFamStandGetrennt',
            'divorced' => 'chbxPersonFamStandGeschieden',
            'partnership_ended' => 'chbxPersonFamStandAufgehobLeben',
        ];
        if (isset($maritalMap[$marital])) {
            $data[$maritalMap[$marital]] = $SEL;
        }

        if (!empty($applicant['start_date'])) {
            $data['chbxPersonAntragBUEGSpaeter'] = $SEL;
            $data['datePersonAntragBUEG'] = $fmt($applicant['start_date']);
        } else {
            $data['chbxPersonAntragBUEGSofort'] = $SEL;
        }

        $pdf = new Pdf(storage_path('app/forms/Bürgergeld_Hauptantrag.pdf'), [
            'command' => env('PDFTK_CMD', '/opt/homebrew/bin/pdftk'),
        ]);

        $pdf->fillForm($data)->dropXfa()->needAppearances()->flatten();
        $content = $pdf->toString();
        if ($content === false) {
            throw new \RuntimeException('HA render failed: '.$pdf->getError());
        }
        return $content;
    }

    private function renderWep(array $applicant, array $person, ?string $bgNumber): ?string
    {
        $template = storage_path('app/forms/WEP.pdf');
        if (!file_exists($template)) {
            \Log::warning('WEP template not found.');
            return null;
        }

        $fmt = function (?string $iso) {
            if (!$iso) {
                return null;
            }
            $t = strtotime($iso);
            return $t ? date('d.m.Y', $t) : $iso;
        };

        if (!isset($person['has_social_number']) && !empty($person['social_number'])) {
            $person['has_social_number'] = '1';
        }

        $data = [];
        $data['txtfPersonVorname'] = $applicant['first_name'] ?? '';
        $data['txtfPersonNachname'] = $applicant['surname'] ?? '';
        $data['datePersonGebDatum'] = $fmt($applicant['date_of_birth'] ?? null);
        if ($bgNumber) {
            $data['txtfBGNr'] = $bgNumber;
        }

        $data['txtfBGVorname'] = $person['first_name'] ?? '';
        $data['txtfBGNachname'] = $person['surname'] ?? '';
        $data['dateBGGebDatum'] = $fmt($person['date_of_birth'] ?? null);
        $data['txtfBGGebName'] = $person['birth_name'] ?? '';
        $data['txtfBGGebOrt'] = $person['birth_location'] ?? '';
        $data['txtfBGGebLand'] = $person['birth_country'] ?? '';
        $data['txtfBGStaatsangehoerigkeit'] = $person['nationality'] ?? '';

        $data['chbxBGMaennlich'] = 'Off';
        $data['chbxBGWeiblich'] = 'Off';
        $data['chbxBGDivers'] = 'Off';
        $data['chbxBGKeine'] = 'Off';
        $gender = strtolower((string)($person['gender'] ?? ''));
        $genderMap = [
            'male' => 'chbxBGMaennlich',
            'female' => 'chbxBGWeiblich',
            'divers' => 'chbxBGDivers',
            'diverse' => 'chbxBGDivers',
            'unspecified' => 'chbxBGKeine',
        ];
        if (isset($genderMap[$gender])) {
            $data[$genderMap[$gender]] = 'selektiert';
        }

        if (array_key_exists('has_social_number', $person)) {
            $data['rbtnRVNr'] = ((string)$person['has_social_number'] === '1') ? 'ja' : 'nein';
        }
        if (!empty($person['social_number'])) {
            $data['txtfRVNr'] = $person['social_number'];
        }
        if (array_key_exists('has_guardian', $person)) {
            $data['rbtnBetreuer'] = $this->boolToken($person['has_guardian'],
                'ja, fuegen Sie eine Kopie der Bestellungsurkunde oder des Betreuerausweises bei',
                'nein'
            );
        }
        if (!empty($person['entry_date'])) {
            $data['dateEinreise'] = $fmt($person['entry_date']);
        }
        if (array_key_exists('has_residence', $person)) {
            $data['rbtnAufenth'] = $this->boolToken($person['has_residence'],
                'ja, fuegen Sie die Aufenthaltsgenehmigung bei',
                'nein'
            );
        }
        if (array_key_exists('has_commitment_declaration', $person)) {
            $data['rbtnVerpflichtungsE'] = $this->boolToken($person['has_commitment_declaration'],
                'ja, fuegen Sie eine Kopie der Verpflichtungserklaerung oder einen anderen Nachweis bei',
                'nein'
            );
        }

        $data['chbxFamilienstandLedig'] = 'Off';
        $data['chbxFamilienstandVerheiratet'] = 'Off';
        $data['chbxFamilienstandVerwitwet'] = 'Off';
        $data['chbxFamilienstandLebenspartnerschaft'] = 'Off';
        $data['chbxFamilienstandGetrennt'] = 'Off';
        $data['chbxFamilienstandGeschieden'] = 'Off';
        $data['chbxFamilienstandAufgehoben'] = 'Off';
        $ms = strtolower((string)($person['marital_status'] ?? ''));
        $msMap = [
            'single' => 'chbxFamilienstandLedig',
            'married' => 'chbxFamilienstandVerheiratet',
            'widowed' => 'chbxFamilienstandVerwitwet',
            'registered' => 'chbxFamilienstandLebenspartnerschaft',
            'separated' => 'chbxFamilienstandGetrennt',
            'divorced' => 'chbxFamilienstandGeschieden',
            'partnership_ended' => 'chbxFamilienstandAufgehoben',
        ];
        if (isset($msMap[$ms])) {
            $data[$msMap[$ms]] = 'selektiert';
        }
        if (!empty($person['status_change_date'])) {
            $data['dateGetrennt'] = $fmt($person['status_change_date']);
        }

        if (array_key_exists('is_related', $person)) {
            $data['rbtnVerwandt'] = $this->boolToken($person['is_related'], 'ja', 'nein');
        }
        if (!empty($person['relationship'])) {
            $data['txtfVerwandt'] = $person['relationship'];
        }
        if (array_key_exists('employable', $person)) {
            $data['rbtnErwerbsfaehig'] = $this->boolToken($person['employable'], 'ja', 'nein');
        }

        $pdf = new Pdf($template, [
            'command' => env('PDFTK_CMD', '/opt/homebrew/bin/pdftk'),
        ]);

        $pdf->fillForm($data)->dropXfa()->needAppearances()->flatten();
        $content = $pdf->toString();
        if ($content === false) {
            throw new \RuntimeException('WEP render failed: '.$pdf->getError());
        }
        return $content;
    }

    private function mergePdfs(array $paths, array &$tmpFiles): string
    {
        $mergedTmp = tempnam(sys_get_temp_dir(), 'merged_');
        if ($mergedTmp === false) {
            throw new \RuntimeException('Failed to allocate merged PDF temp file');
        }

        $pdf = new Pdf($paths, [
            'command' => env('PDFTK_CMD', '/opt/homebrew/bin/pdftk'),
        ]);
        if (!$pdf->saveAs($mergedTmp)) {
            throw new \RuntimeException('PDF merge failed: '.$pdf->getError());
        }
        $tmpFiles[] = $mergedTmp;
        return file_get_contents($mergedTmp);
    }

    private function storeTemp(string $content, string $prefix, array &$tmpFiles): string
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }
        file_put_contents($tmp, $content);
        $tmpFiles[] = $tmp;
        return $tmp;
    }

    private function boolToken($value, string $trueToken, string $falseToken): string
    {
        $normalized = strtolower((string)$value);
        $isTrue = in_array($normalized, ['1', 'true', 'yes', 'ja'], true);
        return $isTrue ? $trueToken : $falseToken;
    }

    private function readDomain(): array
    {
        $path = storage_path('app/domain.json');
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function selectWepCandidate(array $domain): ?array
    {
        $household = $domain['household'] ?? [];
        if (!is_array($household)) {
            return null;
        }
        foreach ($household as $index => $member) {
            if (!is_array($member)) {
                continue;
            }
            $role = strtolower((string) ($member['role'] ?? ''));
            if ($role === 'applicant') {
                continue;
            }
            $age = isset($member['age']) ? (int) $member['age'] : 0;
            $inBg = array_key_exists('in_bg', $member)
                ? in_array(strtolower((string) $member['in_bg']), ['1', 'true', 'yes', 'ja'], true)
                : true;
            if ($age >= 15 && $inBg) {
                return ['index' => $index, 'member' => $member];
            }
        }
        if (!empty($household)) {
            $first = is_array($household[0]) ? $household[0] : [];
            return ['index' => 0, 'member' => $first];
        }
        return null;
    }
}
