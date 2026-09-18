<?php
// app/Models/ImportBatch.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class ImportBatch extends Model
{
    protected string $table = 'import_batches';
    protected array $fillable = ['user_id', 'account_id', 'filename', 'format', 'mapping', 'rows_total', 'rows_imported', 'rows_duplicated', 'status'];
    protected array $json = ['mapping'];
}
