<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GeminiClient;

class AiController extends Controller
{
    public function health(GeminiClient $ai)
    {
        try {
            $out = $ai->generateJson('Return {"ok":true,"model":"'.$this->getModel().'"}');
            return response()->json(['ok' => true, 'gemini' => $out], 200);
        } catch (\Throwable $e) {
            \Log::error('Gemini health failed: '.$e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function annexes(Request $request, GeminiClient $ai)
    {
        $domain = $request->json()->all();
        if (empty($domain)) {
            $domain = $this->readDomain();
        }

        if (empty($domain)) {
            return response()->json(['forms' => [['code' => 'HA']], 'ai_raw' => []]);
        }

        $prompt = <<<RULES
You are an annex selector for German Bürgergeld applications.
Rules:
- Always include {"code":"HA"}.
- Include one {"code":"WEP","for_person_id": "..."} for each BG member age >= 15 who is NOT the main applicant.
- (Future) Include {"code":"KI"} for each BG member age < 15.
- Include {"code":"KDU"} if housing.claims_housing_costs == true.
- Include {"code":"EKS"} if flags.self_employed == true, else include {"code":"EK"} if any income[].type exists.
- Include {"code":"VM"} if assets[] non-empty OR asset declaration required.
Return strict JSON: {"forms":[...], "reasons":[{"code":"WEP","reason":"member p2 is 17"}, ...]}
RULES;

        $out = [];
        try {
            $out = $ai->generateJson($prompt."\n\nDOMAIN_JSON:\n".json_encode($domain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            \Log::warning('Annex selection via Gemini failed: '.$e->getMessage());
        }

        $candidate = $this->selectWepCandidate($domain);
        $forms = [['code' => 'HA']];
        if ($candidate) {
            $forms[] = ['code' => 'WEP', 'for_person_id' => $candidate['member']['id'] ?? null];
        }

        if (isset($out['forms']) && is_array($out['forms']) && count($out['forms'])) {
            $forms = $out['forms'];
        }

        $forms = array_values(array_filter($forms, function ($form) {
            return isset($form['code']) && in_array($form['code'], ['HA', 'WEP'], true);
        }));
        $codes = array_column($forms, 'code');
        if (!in_array('HA', $codes, true)) {
            array_unshift($forms, ['code' => 'HA']);
        }
        if ($candidate && !in_array('WEP', $codes, true)) {
            $forms[] = ['code' => 'WEP', 'for_person_id' => $candidate['member']['id'] ?? null];
        }

        return response()->json([
            'forms' => $forms,
            'ai_raw' => $out,
        ]);
    }

    public function saveDomain(Request $request)
    {
        $payload = $request->json()->all() ?: $request->all();
        $existing = $this->readDomain();

        $domain = $existing;
        if (isset($payload['applicant']) && is_array($payload['applicant'])) {
            $domain['applicant'] = $payload['applicant'];
        }
        if (array_key_exists('household', $payload)) {
            $domain['household'] = $this->normalizeHousehold($payload['household']);
            if (empty($domain['household'])) {
                unset($domain['wep']);
            }
        }
        if (isset($payload['housing'])) {
            $domain['housing'] = $payload['housing'];
        }
        if (isset($payload['income'])) {
            $domain['income'] = $payload['income'];
        }
        if (isset($payload['assets'])) {
            $domain['assets'] = $payload['assets'];
        }
        if (isset($payload['flags'])) {
            $domain['flags'] = $payload['flags'];
        }

        $wepFields = array_column($this->wepSchemaArray(), 'key');
        if (!empty($wepFields)) {
            $flip = array_flip($wepFields);
            if (!empty($domain['household']) && is_array($domain['household'])) {
                foreach ($domain['household'] as $idx => $member) {
                    if (is_array($member)) {
                        $domain['household'][$idx] = array_diff_key($member, $flip);
                    }
                }
            }
        }
        unset($domain['wep']);

        $this->writeDomain($domain);
        return response()->json(['ok' => true, 'domain' => $domain]);
    }

    public function getDomain()
    {
        return response()->json($this->readDomain());
    }

    public function getWepSchema()
    {
        return response()->json($this->wepSchemaArray());
    }

    public function wepQuestions(GeminiClient $ai)
    {
        $domain = $this->readDomain();
        if (empty($domain)) {
            return response()->json([
                'required' => false,
                'questions' => [],
                'message' => 'No HA data available yet.',
            ]);
        }

        $candidate = $this->selectWepCandidate($domain);
        if (!$candidate) {
            return response()->json([
                'required' => false,
                'questions' => [],
                'message' => 'No WEP required for the current household.',
            ]);
        }
        $person = $candidate['member'];

        $schema = $this->wepSchemaArray();
        $existing = $domain['wep'] ?? [];
        $existing = array_merge($person, $existing);

        $schemaForAi = array_map(function ($field) use ($existing) {
            return [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'options' => $field['options'],
                'current_value' => $existing[$field['key']] ?? '',
            ];
        }, $schema);

        $questions = [];
        $prompt = "You receive metadata for WEP (Bürgergeld) fields. "
            ."Return ONLY JSON {\"questions\":[{\"key\":\"\",\"label\":\"\",\"type\":\"\",\"options\":[]}]}. "
            ."Include a question only if current_value is empty. "
            ."Use the provided label unless you can shorten it. "
            ."Use type from the metadata (text/date/select). "
            ."If options are provided, reuse them exactly. "
            ."If nothing is missing, return {\"questions\":[]}.\n\n"
            ."FIELDS:\n".json_encode($schemaForAi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $out = $ai->generateJson($prompt);
            if (!empty($out['questions']) && is_array($out['questions'])) {
                foreach ($out['questions'] as $q) {
                    if (empty($q['key'])) {
                        continue;
                    }
                    $field = collect($schema)->firstWhere('key', $q['key']);
                    if (!$field) {
                        continue;
                    }
                    $questions[] = [
                        'key' => $q['key'],
                        'label' => $q['label'] ?? $field['label'],
                        'type' => $q['type'] ?? $field['type'],
                        'options' => $q['options'] ?? $field['options'],
                        'required' => $field['required'] ?? false,
                        'default' => $existing[$field['key']] ?? '',
                    ];
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Gemini WEP question generation failed: '.$e->getMessage());
        }

        if (empty($questions)) {
            foreach ($schema as $field) {
                $value = $existing[$field['key']] ?? '';
                if ($value === null || $value === '') {
                    $questions[] = [
                        'key' => $field['key'],
                        'label' => $field['label'],
                        'type' => $field['type'],
                        'options' => $field['options'],
                        'required' => $field['required'] ?? false,
                        'default' => $existing[$field['key']] ?? '',
                    ];
                }
            }
        }

        return response()->json([
            'required' => true,
            'questions' => $questions,
        ]);
    }

    public function fieldHelp(Request $request, GeminiClient $ai)
    {
        $form = strtolower((string) $request->input('form'));
        $key = (string) $request->input('key');
        if ($form === '' || $key === '') {
            return response()->json(['error' => 'Missing form or field key'], 422);
        }
        if (!in_array($form, ['ha', 'wep', 'household'], true)) {
            return response()->json(['error' => 'Unsupported form.'], 422);
        }

        $fields = match ($form) {
            'ha' => config('ha.fields', []),
            'wep' => $this->wepSchemaArray(),
            'household' => $this->householdSchema(),
        };
        $field = null;
        foreach ($fields as $item) {
            if (($item['key'] ?? null) === $key) {
                $field = $item;
                break;
            }
        }
        if (!$field) {
            return response()->json(['error' => 'Unknown field'], 404);
        }

        $label = $field['label'] ?? ucfirst(str_replace('_', ' ', $key));
        $type = $field['type'] ?? 'text';
        $options = $field['options'] ?? [];
        $required = $field['required'] ?? false;

        $domain = $this->readDomain();
        $contextValue = '';
        if ($form === 'ha') {
            $contextValue = $domain['applicant'][$key] ?? '';
        } elseif ($form === 'wep') {
            $candidate = $this->selectWepCandidate($domain);
            if ($candidate) {
                $context = array_merge($candidate['member'], $domain['wep'] ?? []);
                $contextValue = $context[$key] ?? '';
            }
        } elseif ($form === 'household') {
            $candidate = $this->selectWepCandidate($domain);
            if ($candidate) {
                $context = $candidate['member'] ?? [];
                $contextValue = $context[$key] ?? ($domain['wep'][$key] ?? '');
            }
        }

        $optionsText = '';
        if (!empty($options)) {
            $choices = array_map(function ($opt) {
                if (is_array($opt)) {
                    return $opt['label'] ?? $opt['value'] ?? '';
                }
                return (string) $opt;
            }, $options);
            $choices = array_filter($choices, fn($c) => $c !== '');
            if (!empty($choices)) {
                $optionsText = ' Available options: '.implode(', ', $choices).'.';
            }
        }

        $prompt = "You help citizens fill in the German Bürgergeld "
            .($form === 'ha' ? "Hauptantrag (HA)" : ($form === 'wep' ? "Anlage WEP" : "Haushaltsangaben")).". "
            ."Explain in at most two sentences what information belongs in the field \"{$label}\" (key \"{$key}\"). "
            ."Field type: {$type}. It is ".($required ? 'required' : 'optional').".{$optionsText} "
            ."Focus on plain-language guidance and mention typical documents if relevant."
            .($contextValue !== '' ? " The currently stored value is \"{$contextValue}\"; mention it only if it clarifies the instruction." : '');

        $helpText = null;
        try {
            if (!empty(config('ai.api_key'))) {
                $helpText = trim($ai->generateText($prompt));
            }
        } catch (\Throwable $e) {
            \Log::warning('Gemini field help failed: '.$e->getMessage());
        }
        if ($helpText === null || $helpText === '') {
            $helpText = $this->fallbackHelpText($label, $type, $options, $required);
        }

        return response()->json(['help' => $helpText]);
    }

    public function chat(Request $request, GeminiClient $ai)
    {
        $form = strtolower((string) $request->input('form'));
        $key = (string) $request->input('key');
        $message = trim((string) $request->input('message', ''));
        if ($form === '' || $key === '' || $message === '') {
            return response()->json(['error' => 'Form, field, and message are required.'], 422);
        }
        if (!in_array($form, ['ha', 'wep', 'household'], true)) {
            return response()->json(['error' => 'Unsupported form.'], 422);
        }

        $fields = match ($form) {
            'ha' => config('ha.fields', []),
            'wep' => $this->wepSchemaArray(),
            'household' => $this->householdSchema(),
        };
        $field = null;
        foreach ($fields as $item) {
            if (($item['key'] ?? null) === $key) {
                $field = $item;
                break;
            }
        }
        if (!$field) {
            return response()->json(['error' => 'Unknown field'], 404);
        }

        $label = $field['label'] ?? ucfirst(str_replace('_', ' ', $key));
        $type = $field['type'] ?? 'text';
        $options = $field['options'] ?? [];
        $required = $field['required'] ?? false;

        $domain = $this->readDomain();
        $contextValue = '';
        if ($form === 'ha') {
            $contextValue = $domain['applicant'][$key] ?? '';
        } elseif ($form === 'wep') {
            $candidate = $this->selectWepCandidate($domain);
            if ($candidate) {
                $context = array_merge($candidate['member'], $domain['wep'] ?? []);
                $contextValue = $context[$key] ?? '';
            }
        } elseif ($form === 'household') {
            $candidate = $this->selectWepCandidate($domain);
            if ($candidate) {
                $context = $candidate['member'] ?? [];
                $contextValue = $context[$key] ?? ($domain['wep'][$key] ?? '');
            }
        }

        $baseGuidance = $this->fallbackHelpText($label, $type, $options, $required);

        $history = $request->input('history', []);
        if (!is_array($history)) {
            $history = [];
        }
        $conversation = [];
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $role = strtolower((string) ($entry['role'] ?? ''));
            $content = trim((string) ($entry['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $conversation[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }
        $conversation = array_slice($conversation, -12);

        $conversation[] = ['role' => 'user', 'content' => $message];

        while (!empty($conversation) && $conversation[0]['role'] !== 'user') {
            array_shift($conversation);
        }

        $optionsText = '';
        if (!empty($options)) {
            $choices = array_map(function ($opt) {
                if (is_array($opt)) {
                    return $opt['label'] ?? $opt['value'] ?? '';
                }
                return (string) $opt;
            }, $options);
            $choices = array_filter($choices, fn($c) => $c !== '');
            if (!empty($choices)) {
                $optionsText = ' Options: '.implode(', ', $choices).'.';
            }
        }

        $systemPrompt = "You are a helpful assistant guiding citizens through the German Bürgergeld "
            .($form === 'ha' ? "Hauptantrag (HA)" : ($form === 'wep' ? "Anlage WEP" : "Haushaltsangaben"))
            ." form. Focus strictly on the field \"{$label}\" (key \"{$key}\"). "
            ."Field type: {$type}. It is ".($required ? 'required' : 'optional').".{$optionsText} "
            ."Base guidance: {$baseGuidance} "
            .($contextValue !== '' ? "Current stored value (do not repeat unless necessary): {$contextValue}. " : '')
            ."Give concise, practical answers (max 3 sentences). "
            ."Do not invent personal data and do not mention stored values. "
            ."If the user drifts away from the field, politely steer them back.";

        $reply = null;
        try {
            if (!empty(config('ai.api_key'))) {
                $reply = trim($ai->chat($conversation, $systemPrompt));
            }
        } catch (\Throwable $e) {
            \Log::warning('Gemini chat help failed: '.$e->getMessage());
        }
        if ($reply === null || $reply === '') {
            $reply = $baseGuidance.' Wenn Sie unsicher sind, sehen Sie bitte in den amtlichen Erläuterungen nach oder wenden Sie sich an Ihr Jobcenter.';
        }

        return response()->json(['reply' => $reply]);
    }

    public function saveWep(Request $request)
    {
        $wepData = $request->all();
        $domain = $this->readDomain();
        if (empty($domain)) {
            return response()->json(['ok' => false, 'error' => 'No HA data saved yet'], 422);
        }

        $boolKeys = ['has_social_number', 'has_guardian', 'has_residence', 'has_commitment_declaration', 'is_related', 'employable'];
        $normalized = $domain['wep'] ?? [];
        foreach ($wepData as $key => $value) {
            if (in_array($key, $boolKeys, true)) {
                $normalized[$key] = $this->parseBoolean($value) ? '1' : '0';
            } else {
                $normalized[$key] = $value;
            }
        }

        $domain['wep'] = $normalized;
        $candidate = $this->selectWepCandidate($domain);
        if ($candidate) {
            $index = $candidate['index'];
            $domain['household'][$index] = array_merge($domain['household'][$index], $normalized);
        }

        $this->writeDomain($domain);

        return response()->json(['ok' => true, 'wep' => $normalized]);
    }

    private function domainPath(): string
    {
        return storage_path('app/domain.json');
    }

    private function readDomain(): array
    {
        $path = $this->domainPath();
        if (!file_exists($path)) {
            return [];
        }
        $decoded = json_decode(file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeDomain(array $domain): void
    {
        $path = $this->domainPath();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($domain, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeHousehold($household): array
    {
        if (!is_array($household)) {
            return [];
        }
        $normalized = [];
        foreach ($household as $index => $member) {
            if (!is_array($member)) {
                continue;
            }
            if (!isset($member['id']) || $member['id'] === '') {
                $member['id'] = 'p'.($index + 2);
            }
            if (isset($member['age'])) {
                $member['age'] = (int) $member['age'];
            }
            if (array_key_exists('in_bg', $member)) {
                $member['in_bg'] = $this->parseBoolean($member['in_bg']);
            }
            $normalized[] = $member;
        }
        return $normalized;
    }

    private function parseBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower((string) $value);
        return in_array($normalized, ['1', 'true', 'yes', 'ja'], true);
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
            $inBg = array_key_exists('in_bg', $member) ? $this->parseBoolean($member['in_bg']) : true;
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

    private function wepSchemaArray(): array
    {
        $path = base_path('WEP_fields.txt');
        if (!file_exists($path)) {
            return [];
        }

        $required = config('wep.required_keys', []);
        $fieldMap = [
            'txtfBGVorname' => 'first_name',
            'txtfBGNachname' => 'surname',
            'dateBGGebDatum' => 'date_of_birth',
            'txtfBGGebName' => 'birth_name',
            'txtfBGGebOrt' => 'birth_location',
            'txtfBGGebLand' => 'birth_country',
            'txtfBGStaatsangehoerigkeit' => 'nationality',
            'chbxBGMaennlich' => 'gender',
            'chbxBGWeiblich' => 'gender',
            'chbxBGDivers' => 'gender',
            'chbxBGKeine' => 'gender',
            'rbtnRVNr' => 'has_social_number',
            'txtfRVNr' => 'social_number',
            'rbtnBetreuer' => 'has_guardian',
            'dateEinreise' => 'entry_date',
            'rbtnAufenth' => 'has_residence',
            'rbtnVerpflichtungsE' => 'has_commitment_declaration',
            'chbxFamilienstandLedig' => 'marital_status',
            'chbxFamilienstandVerheiratet' => 'marital_status',
            'chbxFamilienstandVerwitwet' => 'marital_status',
            'chbxFamilienstandLebenspartnerschaft' => 'marital_status',
            'chbxFamilienstandGetrennt' => 'marital_status',
            'chbxFamilienstandGeschieden' => 'marital_status',
            'chbxFamilienstandAufgehoben' => 'marital_status',
            'dateGetrennt' => 'status_change_date',
            'rbtnVerwandt' => 'is_related',
            'txtfVerwandt' => 'relationship',
            'rbtnErwerbsfaehig' => 'employable',
        ];

        $labels = [
            'first_name' => 'First name',
            'surname' => 'Last name',
            'date_of_birth' => 'Date of birth',
            'birth_name' => 'Birth name',
            'birth_location' => 'Birth location',
            'birth_country' => 'Birth country',
            'nationality' => 'Nationality',
            'gender' => 'Gender',
            'has_social_number' => 'Has social insurance number?',
            'social_number' => 'Social insurance number',
            'has_guardian' => 'Court-appointed guardian?',
            'entry_date' => 'Entry date',
            'has_residence' => 'Residence permit available?',
            'has_commitment_declaration' => 'Commitment declaration provided?',
            'marital_status' => 'Marital status',
            'status_change_date' => 'Status change date',
            'is_related' => 'Related to applicant?',
            'relationship' => 'Relationship',
            'employable' => 'Employable?',
        ];

        $types = [
            'date_of_birth' => 'date',
            'entry_date' => 'date',
            'status_change_date' => 'date',
            'gender' => 'select',
            'has_social_number' => 'select',
            'has_guardian' => 'select',
            'has_residence' => 'select',
            'has_commitment_declaration' => 'select',
            'marital_status' => 'select',
            'is_related' => 'select',
            'employable' => 'select',
        ];

        $options = [
            'gender' => [
                ['value' => 'female', 'label' => 'Female'],
                ['value' => 'male', 'label' => 'Male'],
                ['value' => 'divers', 'label' => 'Divers'],
                ['value' => 'unspecified', 'label' => 'Unspecified'],
            ],
            'has_social_number' => [
                ['value' => '1', 'label' => 'Yes'],
                ['value' => '0', 'label' => 'No'],
            ],
            'has_guardian' => [
                ['value' => '1', 'label' => 'Yes'],
                ['value' => '0', 'label' => 'No'],
            ],
            'has_residence' => [
                ['value' => '1', 'label' => 'Yes'],
                ['value' => '0', 'label' => 'No'],
            ],
            'has_commitment_declaration' => [
                ['value' => '1', 'label' => 'Yes'],
                ['value' => '0', 'label' => 'No'],
            ],
            'marital_status' => [
                ['value' => 'single', 'label' => 'Single'],
                ['value' => 'married', 'label' => 'Married'],
                ['value' => 'widowed', 'label' => 'Widowed'],
                ['value' => 'registered', 'label' => 'Registered partnership'],
                ['value' => 'separated', 'label' => 'Separated'],
                ['value' => 'divorced', 'label' => 'Divorced'],
                ['value' => 'partnership_ended', 'label' => 'Partnership ended'],
            ],
            'is_related' => [
                ['value' => '1', 'label' => 'Yes'],
                ['value' => '0', 'label' => 'No'],
            ],
            'employable' => [
                ['value' => '1', 'label' => 'Yes'],
                ['value' => '0', 'label' => 'No'],
            ],
        ];

        $fields = [];
        $seenKeys = [];
        foreach (file($path) as $line) {
            if (preg_match('/FieldName:\s*(\S+)/', $line, $match)) {
                $pdfField = $match[1];
                if (!isset($fieldMap[$pdfField])) {
                    continue;
                }
                $key = $fieldMap[$pdfField];
                if (isset($seenKeys[$key])) {
                    continue;
                }
                $fields[] = [
                    'key' => $key,
                    'label' => $labels[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                    'type' => $types[$key] ?? 'text',
                    'options' => $options[$key] ?? null,
                    'required' => in_array($key, $required, true),
                ];
                $seenKeys[$key] = true;
            }
        }

        return array_values($fields);
    }

    private function householdSchema(): array
    {
        return [
            [
                'key' => 'section_overview',
                'label' => 'Household composition',
                'type' => 'text',
                'options' => null,
                'required' => false,
            ],
            [
                'key' => 'hh_first_name',
                'label' => 'Partner first name',
                'type' => 'text',
                'options' => null,
                'required' => true,
            ],
            [
                'key' => 'hh_surname',
                'label' => 'Partner last name',
                'type' => 'text',
                'options' => null,
                'required' => true,
            ],
            [
                'key' => 'hh_age',
                'label' => 'Partner age',
                'type' => 'number',
                'options' => null,
                'required' => true,
            ],
            [
                'key' => 'hh_relationship',
                'label' => 'Relationship to applicant',
                'type' => 'text',
                'options' => null,
                'required' => true,
            ],
            [
                'key' => 'hh_in_bg',
                'label' => 'In same Bedarfsgemeinschaft?',
                'type' => 'select',
                'options' => [
                    ['value' => 'true', 'label' => 'Yes'],
                    ['value' => 'false', 'label' => 'No'],
                ],
                'required' => true,
            ],
            [
                'key' => 'hh_gender',
                'label' => 'Partner gender',
                'type' => 'select',
                'options' => [
                    ['value' => 'female', 'label' => 'Female'],
                    ['value' => 'male', 'label' => 'Male'],
                    ['value' => 'divers', 'label' => 'Divers'],
                    ['value' => 'unspecified', 'label' => 'Unspecified'],
                ],
                'required' => true,
            ],
        ];
    }

    private function getModel(): string
    {
        return (string) config('ai.model', 'gemini-2.0-flash');
    }

    private function fallbackHelpText(string $label, string $type, array $options, bool $required): string
    {
        $base = $required
            ? "Please provide {$label} exactly as it appears in your documents."
            : "You may provide {$label} if it is relevant for your situation.";

        if ($type === 'date') {
            $base = ($required ? 'Please enter' : 'You can enter')." {$label} in the format TT.MM.JJJJ.";
        } elseif ($type === 'number') {
            $base = ($required ? 'Please enter' : 'You can enter')." {$label} as a whole number (digits only).";
        } elseif (!empty($options)) {
            $choices = array_map(function ($opt) {
                if (is_array($opt)) {
                    return $opt['label'] ?? $opt['value'] ?? '';
                }
                return (string) $opt;
            }, $options);
            $choices = array_filter($choices, fn($c) => $c !== '');
            if (!empty($choices)) {
                $base .= ' Choose one of: '.implode(', ', $choices).'.';
            }
        }

        return $base;
    }
}
