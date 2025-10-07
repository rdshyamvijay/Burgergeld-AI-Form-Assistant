<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use mikehaertl\pdftk\Pdf;

class CitizenFormPdfController extends Controller
{
    public function generate(Request $request)
    {
        $p = $request->all();

        $fmtDate = function (?string $iso) {
            if (!$iso) return null;
            $t = strtotime($iso);
            return $t ? date('d.m.Y', $t) : $iso; // TT.MM.JJJJ
        };

        // JSON -> PDF field names (pages 1–2 only)
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
        foreach ($map as $json => $pdfField) {
            if (isset($p[$json]) && $p[$json] !== null && $p[$json] !== '') {
                $data[$pdfField] = $json === 'date_of_birth'
                    ? $fmtDate($p[$json])
                    : $p[$json];
            }
        }

        // --- CHECKBOX/RADIO HELPERS ---
        $ON = 'On'; // generic ON fallback
        $OFF = 'Off';
        $ON_SEL = 'selektiert'; // general ON value used by this PDF for many checkboxes
        $ON_GENDER = 'selektiert'; // gender ON
        $ON_FAM = 'selektiert';    // familienstand ON

        // 8) Gender (männlich / weiblich / divers / keine Angabe)
        // Force all to OFF first, then set the selected one to the field's ON value to ensure appearance renders.
        $data['chbxPersonMaennlich'] = $OFF;
        $data['chbxPersonWeiblich']  = $OFF;
        $data['chbxPersonDivers']    = $OFF;
        $data['chbxPersonKeine']     = $OFF;

        if (!empty($p['gender'])) {
            $g = strtolower($p['gender']);
            if ($g === 'male') {
                $data['chbxPersonMaennlich'] = $ON_GENDER;
            } elseif ($g === 'female') {
                $data['chbxPersonWeiblich'] = $ON_GENDER;
            } elseif ($g === 'divers' || $g === 'diverse') {
                $data['chbxPersonDivers'] = $ON_GENDER;
            } else {
                $data['chbxPersonKeine'] = $ON_GENDER;
            }
        } else {
            $data['chbxPersonKeine'] = $ON_GENDER;
        }

        // 14) Kein fester Wohnsitz vorhanden
        if (!empty($p['no_fixed_residence']) && (string)$p['no_fixed_residence'] === '1') {
            $data['chbxWohnsitz'] = $ON_SEL;
        }

        // 16/17) Bank account checkbox
        if (isset($p['has_bank_account']) && (string)$p['has_bank_account'] === '0') {
            $data['chbxKonto'] = $ON_SEL; // 'selektiert' per fields dump
        }

        // 18/19) Sozial-/Rentenversicherungsnummer
        if (array_key_exists('social_number', $p) && !empty($p['social_number'])) {
            $data['rbtnPersonSVRVNr'] = 'ja';
            $data['txtfPersonSVRVNr'] = $p['social_number'];
        } else {
            $data['rbtnPersonSVRVNr'] = 'nein';
        }

        // 22) Aufenthaltsgenehmigung
        if (!empty($p['nationality']) && strtoupper($p['nationality']) !== 'DE' && isset($p['has_residence'])) {
            $data['rbtnPersonAufenthaltsgenehm'] = ((string)$p['has_residence'] === '1')
                ? 'ja, fuegen Sie die Aufenthaltsgenehmigung bei'
                : 'nein';
        }

        // 24) Familienstand
        // Force all to OFF first to avoid appearance glitches.
        $data['chbxPersonFamStandLedig']         = $OFF;
        $data['chbxPersonFamStandVerheiratet']   = $OFF;
        $data['chbxPersonFamStandVerwitwet']     = $OFF;
        $data['chbxPersonFamStandEingetrLeben']  = $OFF;
        $data['chbxPersonFamStandGetrennt']      = $OFF;
        $data['chbxPersonFamStandGeschieden']    = $OFF;
        $data['chbxPersonFamStandAufgehobLeben'] = $OFF;

        if (!empty($p['marital_status'])) {
            $ms = strtolower($p['marital_status']);
            $mapMS = [
                'single'            => 'chbxPersonFamStandLedig',
                'married'           => 'chbxPersonFamStandVerheiratet',
                'widowed'           => 'chbxPersonFamStandVerwitwet',
                'registered'        => 'chbxPersonFamStandEingetrLeben',
                'separated'         => 'chbxPersonFamStandGetrennt',
                'divorced'          => 'chbxPersonFamStandGeschieden',
                'partnership_ended' => 'chbxPersonFamStandAufgehobLeben',
            ];
            if (isset($mapMS[$ms])) {
                $data[$mapMS[$ms]] = $ON_FAM; // use form's ON state
            }
        }

        // 26) Antragstellung
        if (!empty($p['start_date'])) {
            $data['chbxPersonAntragBUEGSpaeter'] = $ON_SEL;
            $data['datePersonAntragBUEG']        = $fmtDate($p['start_date']);
        } else {
            $data['chbxPersonAntragBUEGSofort']  = $ON_SEL;
        }

        $template = storage_path('app/forms/Bürgergeld_Hauptantrag.pdf'); // adjust name if you renamed
        $pdf = new Pdf($template, [
            // If pdftk isn't found, uncomment and set the full path:
            'command' => '/opt/homebrew/bin/pdftk',
        ]);

        $pdf->fillForm($data)
            ->dropXfa()
            ->flatten();

        $content = $pdf->toString();
        if ($content === false) {
            return response()->json(['error' => $pdf->getError()], 500);
        }

        return response($content, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="Hauptantrag_Buergergeld_filled.pdf"');
    }
}
