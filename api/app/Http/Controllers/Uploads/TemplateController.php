<?php
// api/app/Http/Controllers/Uploads/TemplateController.php
namespace App\Http\Controllers\Uploads;

use App\Http\Controllers\Controller;
use App\Support\CsvWriter;
use Illuminate\Http\Response;

/**
 * The upload template: a header only, so example figures can never be uploaded as real sales.
 * Food, drinks and covers are optional columns (Plan E); left blank, they are ignored.
 * Signed-in users only; no org header, so the browser can fetch it as a plain download link.
 */
class TemplateController extends Controller
{
    public function __invoke(): Response
    {
        return response(CsvWriter::write(['date', 'revenue', 'transactions', 'food', 'drinks', 'covers'], []), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=sales-template.csv',
        ]);
    }
}
