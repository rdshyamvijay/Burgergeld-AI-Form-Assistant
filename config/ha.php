<?php
return [
  // HA pages 1–2 (A + first part of B) – keys we store in domain.applicant
  'fields' => [
    // A. Persönliche Daten
    ['key'=>'first_name',      'label'=>'First name',                 'type'=>'text',  'required'=>true],  // -> txtfPersonVorname
    ['key'=>'surname',         'label'=>'Last name',                  'type'=>'text',  'required'=>true],  // -> txtfPersonNachname
    ['key'=>'birth_name',      'label'=>'Birth name (if different)',  'type'=>'text'],                     // -> txtfPersonGebName
    ['key'=>'date_of_birth',   'label'=>'Date of birth',              'type'=>'date',  'required'=>true],  // -> datePersonGebDatum
    ['key'=>'birth_location',  'label'=>'Birth place',                'type'=>'text'],                     // -> txtfPersonGebOrt
    ['key'=>'birth_country',   'label'=>'Birth country',              'type'=>'text','default'=>'DE'],     // -> txtfPersonGebLand
    ['key'=>'nationality',     'label'=>'Nationality',                'type'=>'text','default'=>'DE'],     // -> txtfPersonStaatsangehoerigkeit
    ['key'=>'gender',          'label'=>'Gender',                     'type'=>'select','options'=>['male','female','divers','unspecified'], 'required'=>true], // -> chbxPerson*

    ['key'=>'address_street',  'label'=>'Street',                      'type'=>'text'], // -> txtfPersonStr
    ['key'=>'address_no',      'label'=>'House number',                'type'=>'text'], // -> txtfPersonHausnr
    ['key'=>'address_postal',  'label'=>'Postal code',                 'type'=>'text'], // -> txtfPersonPlz
    ['key'=>'address_city',    'label'=>'City',                        'type'=>'text'], // -> txtfPersonOrt
    ['key'=>'adress_mailbox',  'label'=>'P.O. Box (optional)',         'type'=>'text'], // -> txtfPersonPostfach
    ['key'=>'phone',           'label'=>'Phone',                       'type'=>'text'], // -> txtfPersonTel

    // Bank
    ['key'=>'account_holder',  'label'=>'Account holder',              'type'=>'text'], // -> txtfKontoinhaber
    ['key'=>'account_iban',    'label'=>'IBAN',                        'type'=>'text'], // -> txtfIBAN
    ['key'=>'has_bank_account','label'=>'No bank account?',            'type'=>'select','options'=>['0','1'],'hint'=>'1 = tick “no bank account”'], // -> chbxKonto

    // IDs / permits (still on page 1–2)
    ['key'=>'social_number',   'label'=>'Social insurance no.',        'type'=>'text'], // -> rbtnPersonSVRVNr + txtfPersonSVRVNr
    ['key'=>'has_guardian',    'label'=>'Court-appointed guardian?',   'type'=>'select','options'=>['0','1']], // -> rbtnPersonBetreuer
    ['key'=>'entry_date',      'label'=>'Entry to Germany (if not DE)','type'=>'date'], // -> datePersonEinreise
    ['key'=>'has_residence',   'label'=>'Residence permit (if not DE)?','type'=>'select','options'=>['0','1']], // -> rbtnPersonAufenthaltsgenehm
    ['key'=>'has_commitment_declaration','label'=>'Commitment declaration given?','type'=>'select','options'=>['0','1']], // -> rbtnPersonVerpflichtungserkl

    // Familienstand (radio group on HA page 2)
    ['key'=>'marital_status',  'label'=>'Marital status', 'type'=>'select',
      'options'=>['single','married','widowed','registered','separated','divorced','partnership_ended']], // -> chbxPersonFamStand*
    ['key'=>'status_change_date','label'=>'Separation/divorce/partnership ended since','type'=>'date'],     // -> datePersonGetrennt

    // Antragstellung
    ['key'=>'start_date',      'label'=>'Start date (empty = ab sofort)','type'=>'date'],                  // -> chbxPersonAntragBUEG* + datePersonAntragBUEG
  ],
];
