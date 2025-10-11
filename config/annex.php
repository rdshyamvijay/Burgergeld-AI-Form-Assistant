<?php
return [
    // minimal WEP set for demo; extend anytime
    'wep_required_fields' => [
        // key => label shown to the user (AI can rephrase, but we keep a fallback)
        'gender'                        => 'Geschlecht',
        'social_number'                 => 'Renten-/Sozialversicherungsnummer',
        'has_guardian'                  => 'Gibt es einen Betreuer / eine Betreuerin?',
        'has_residence'                 => 'Aufenthaltsgenehmigung vorhanden?',
        'entry_date'                    => 'Einreisedatum (falls nicht deutsch)',
        'has_commitment_declaration'    => 'Verpflichtungserklärung abgegeben?',
        'marital_status'                => 'Familienstand',
        'status_change_date'            => 'Datum Trennung/Scheidung/Aufhebung (falls zutreffend)',
        'is_related'                    => 'Mit Antragsteller verwandt?',
        'relationship'                  => 'Verwandtschaftsverhältnis / Beziehung',
        'employable'                    => 'Erwerbsfähig?'
    ],
    // client-side options (optional; AI may propose richer texts)
    'wep_field_options' => [
        'gender'         => ['male','female','divers','unspecified'],
        'marital_status' => ['single','married','widowed','registered','separated','divorced','partnership_ended'],
        'has_guardian'   => ['0','1'],
        'has_residence'  => ['0','1'],
        'has_commitment_declaration' => ['0','1'],
        'is_related'     => ['0','1'],
        'employable'     => ['0','1']
    ],
];
