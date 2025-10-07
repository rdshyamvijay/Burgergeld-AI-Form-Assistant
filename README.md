# Bürgergeld PDF Filler (Laravel 12 + pdftk)

This project is a small Laravel 12 API that accepts JSON input and returns a **filled Bürgergeld Hauptantrag PDF (pages 1–2)** as a downloadable file.  
It uses the `mikehaertl/pdftk` PHP library and the system `pdftk` command to map JSON fields to form fields inside the provided official PDF template.

---

## 🧠 Overview

- Input: a JSON file containing form data  
- Process: the API maps each JSON field to the correct PDF form field and fills it  
- Output: a fully filled Bürgergeld form (first two pages) returned as a downloadable PDF  

This implementation is based on the field structure of the official **Bürgergeld_Hauptantrag.pdf** and handles:
- Text fields (e.g., name, address, date of birth)
- Checkboxes and radio buttons (`selektiert`, `ja`, `nein`, etc.)
- German-specific form options (e.g., marital status, gender, residence permit)

---

## 🛠️ Requirements

| Component | Minimum Version | Notes |
|------------|----------------|-------|
| PHP | 8.3+ | Required by Laravel 12 |
| Composer | Latest | For dependency management |
| Laravel | 12 | Framework |
| pdftk | any | Used by `mikehaertl/pdftk` library |

### 🧩 Install pdftk

**macOS (Homebrew / Apple Silicon):**
```bash
brew install pdftk-java
which pdftk
# usually /opt/homebrew/bin/pdftk
```

Windows:
```
Download and install PDFtk Server.
```
⸻

📦 Project Setup

1. Clone the repository
```
git clone https://github.com/<your-username>/json-pdf-buergergeld.git
cd json-pdf-buergergeld
```
2. Install dependencies

composer install

3. Configure Laravel
```
cp .env.example .env
php artisan key:generate
```
(No database needed — this project is purely file-based.)

⸻

🧾 PDF Template

Place your blank Bürgergeld form inside:
```
storage/app/forms/Bürgergeld_Hauptantrag.pdf
```
⚠️ Important: the filename must match exactly, including the umlaut.
If your system has encoding issues, rename the file (e.g., Buergergeld_Hauptantrag.pdf) and update this line inside the controller:
```
$template = storage_path('app/forms/Buergergeld_Hauptantrag.pdf');
```


⸻

🚀 Run the API

Start the local Laravel server:
```
php artisan serve
```
You’ll see:
```
Starting Laravel development server: http://127.0.0.1:8000
```
The main endpoint is:
```
POST http://127.0.0.1:8000/api/citizen-form-pdf
```

⸻

🧠 Example Usage

Create a test.json
```
{
  "first_name": "Max",
  "surname": "Mustermann",
  "date_of_birth": "2002-11-21",
  "birth_location": "Berlin",
  "birth_country": "DE",
  "nationality": "DE",
  "gender": "male",
  "address_street": "Seydlitzviertel",
  "address_no": "12",
  "address_postal": "16303",
  "address_city": "Schwedt",
  "phone": "017655919583",
  "account_holder": "Max Mustermann",
  "account_iban": "DE123456789",
  "marital_status": "single",
  "start_date": "2025-10-06"
}
```
Test via curl
```
curl -X POST "http://127.0.0.1:8000/api/citizen-form-pdf" \
  -H "Content-Type: application/json" \
  --data-binary @test.json \
  --output filled_form.pdf
```
When successful, you’ll get a filled_form.pdf downloaded to your local folder.

⸻

🧩 API Endpoint

Method	Endpoint	Description
POST	/api/citizen-form-pdf	Accepts JSON and returns a filled PDF download

Routing (routes/api.php):
```
use App\Http\Controllers\CitizenFormPdfController;

Route::post('/citizen-form-pdf', [CitizenFormPdfController::class, 'generate']);
```

⸻

🧱 How It Works

Field Mapping

Inside CitizenFormPdfController.php, there’s a $map array:
```
$map = [
    'first_name'     => 'txtfPersonVorname',
    'surname'        => 'txtfPersonNachname',
    'date_of_birth'  => 'datePersonGebDatum',
    ...
];
```
The script:
	1.	Reads your JSON input
	2.	Maps it to the PDF field names
	3.	Sets checkbox/radio values using the exact FieldStateOption found in the form
	4.	Calls:
```
$pdf->fillForm($data)
    ->dropXfa()
    ->flatten();
```
This fills the data, removes XFA (so Mac Preview works), and flattens the PDF (bakes checkmarks permanently).

⸻

🧩 Field Behavior Summary

Field	Type	ON value used	Comment
Gender	checkbox	selektiert	All four OFF → one ON
Bank account missing	checkbox	selektiert	chbxKonto
Residence permit	radio	ja, fuegen Sie die Aufenthaltsgenehmigung bei / nein	Exact match required
Marital status	checkbox	selektiert	All 7 handled
Application start	checkbox	selektiert	Handles “ab sofort” / “ab späterem Zeitpunkt”
No fixed residence	checkbox	selektiert	Optional



📁 Repository Structure
```
citizen-form/
├── app/
│   └── Http/Controllers/CitizenFormPdfController.php
├── routes/
│   └── api.php
├── storage/
│   └── app/forms/Bürgergeld_Hauptantrag.pdf
├── test.json
├── README.md
├── composer.json
└── artisan
```



⸻

📬 About

Author: Shyamkumar Selvakumar
Description: Task project — mapping Bürgergeld application JSON to PDF.
Framework: Laravel 12
Language: PHP
Tooling: PDFtk + mikehaertl/pdftk wrapper

⸻

🧾 License

MIT License — you can freely reuse or modify this project.
