# AI supplier document imports

Owners can choose **AI document extraction** on the Supplier Price page. The app
uses the existing `OPENAI_API_KEY` and `OPENAI_BASE_URL` settings. Set
`OPENAI_SUPPLIER_IMPORT_MODEL` to override the existing `OPENAI_MODEL` for extraction;
the selected model must support the input file type, vision for PDFs/images, and
Responses API structured outputs. Normal CSV/XLSX imports do not call AI.

Supported inputs: PDF, DOCX, TXT, CSV, XLSX, PNG, JPEG, and WEBP. AI uploads are
limited to 5 MB and 200 product items. Spreadsheet sheets are read locally in full,
with a 2,000 nonempty-row / 200,000-character safety limit before submission. An
incomplete or oversized response is rejected; it is never silently published.
Use the standard importer for formatted lists of up to 2,000 items.
For scanned pictures embedded in Word, upload a PDF or the original image instead;
non-PDF document extraction reads text, not embedded pictures.

The selected document is transmitted to the configured OpenAI service. Requests
use `store: false`; the app does not create a permanent API file upload. Provider
data handling still follows the provider's account settings and policies.
Documents are processed from PHP's temporary upload path, without saving a
public copy. API costs apply. Extraction quality depends on legibility and layout.

Detected identifiers, names, unit prices, currency, and source references are
staged as a pending import. Missing values and model uncertainty block approval.
Owners can open **Review / correct**, compare against their original document,
correct the values, and confirm review. Duplicate SKUs remain blocked until fixed.
Only pending, unarchived import rows can be edited. Approval publishes supplier
prices; it does not update store costs or inventory.

Deployment: run `php artisan migrate --force` after deploying the migration and
source, rebuild assets with `npm run build`, then clear route/config/view caches
using the project's usual deployment procedure. Check that the web server's PHP
request timeout permits the configured extraction call (up to 60 seconds).

Protocol references: [file inputs](https://developers.openai.com/api/docs/guides/file-inputs)
and [structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs).
