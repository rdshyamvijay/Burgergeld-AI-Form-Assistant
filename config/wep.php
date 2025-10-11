<?php
return [
  'required_keys' => [
    // A. (antragstellende Person) — we’ll fill from applicant automatically:
    // applicant_first_name/applicant_surname/applicant_dob are derived; BG number optional.

    // B. Person for whom WEP is filled (pX)
    'first_name',              // -> txtfBGVorname
    'surname',                 // -> txtfBGNachname
    'date_of_birth',           // -> dateBGGebDatum
    'birth_name',              // -> txtfBGGebName
    'birth_location',          // -> txtfBGGebOrt
    'birth_country',           // -> txtfBGGebLand
    'nationality',             // -> txtfBGStaatsangehoerigkeit
    'gender',                  // -> chbxBGMaennlich/Weiblich/Divers/Keine

    // IDs/permits (person)
    'has_social_number',       // -> rbtnRVNr
    'social_number',           // -> txtfRVNr
    'has_guardian',            // -> rbtnBetreuer
    'entry_date',              // -> dateEinreise
    'has_residence',           // -> rbtnAufenth
    'has_commitment_declaration', // -> rbtnVerpflichtungsE

    // Status
    'marital_status',          // -> chbxFamilienstand*
    'status_change_date',      // -> dateGetrennt
    'is_related',              // -> rbtnVerwandt
    'relationship',            // -> txtfVerwandt
    'employable',              // -> rbtnErwerbsfaehig
  ],

  // radio/checkbox choices for WEP (first two pages)
  'enums' => [
    'gender' => ['male','female','divers','unspecified'],        // -> chbxBG*
    'has_social_number' => ['0','1'],                            // -> rbtnRVNr ja/nein
    'has_guardian' => ['0','1'],                                 // -> rbtnBetreuer ja/nein
    'has_residence' => ['0','1'],                                // -> rbtnAufenth ja/nein
    'has_commitment_declaration' => ['0','1'],                   // -> rbtnVerpflichtungsE ja/nein
    'marital_status' => ['single','married','widowed','registered','separated','divorced','partnership_ended'],
    'is_related' => ['0','1'],                                   // -> rbtnVerwandt ja/nein
    'employable' => ['0','1'],                                   // -> rbtnErwerbsfaehig ja/nein
  ],
];
