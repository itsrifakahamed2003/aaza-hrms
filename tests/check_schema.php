<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Database Schema & SQL Integrity Checker (CLI)
 * 
 * 1. Parses database/schema.sql and all database/phase*.sql files to build
 *    the definitive list of tables and columns (including dynamic ALTER TABLE statements).
 * 2. Scans every PHP file's SQL strings:
 *    - Table names after FROM, JOIN, INTO, UPDATE
 *    - Column lists in INSERT INTO table (col1, col2, ...)
 *    - Column lists in UPDATE table SET col1 = ..., col2 = ...
 *    - alias.column and table.column references in SELECT, JOIN, WHERE, ORDER BY, etc.
 * 3. Exits with non-zero status (1) if any unknown table or column is detected.
 */

$rootDir = dirname(__DIR__);

// 1. Build Schema from SQL files
$schema = [];

function strip_sql_comments(string $sql): string {
    $sql = preg_replace('~/\*.*?\*/~s', '', $sql);
    $sql = preg_replace('~--[^\r\n]*~', '', $sql);
    $sql = preg_replace('~#[^\r\n]*~', '', $sql);
    return $sql ?? '';
}

function add_schema_column(array &$schema, string $table, string $column): void {
    $table = strtolower(trim($table, "` \t\r\n"));
    $column = strtolower(trim($column, "` \t\r\n"));
    if ($table === '' || $column === '') {
        return;
    }
    if (!isset($schema[$table])) {
        $schema[$table] = [];
    }
    $schema[$table][$column] = true;
}

$sqlFiles = array_merge(
    [$rootDir . '/database/aaza_hrms_full.sql'],
    glob($rootDir . '/database/migrations/*.sql') ?: []
);

sort($sqlFiles);

$skipKeywords = [
    'primary', 'key', 'index', 'unique', 'constraint', 'foreign', 'check', 'fulltext'
];

foreach ($sqlFiles as $sqlPath) {
    if (!is_file($sqlPath)) {
        continue;
    }
    $raw = (string)file_get_contents($sqlPath);
    $cleaned = strip_sql_comments($raw);

    // 1. CREATE TABLE [IF NOT EXISTS] `tbl` ( ... )
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([`\w]+)\s*\((.*?)\)\s*(?:ENGINE|DEFAULT|COLLATE|;)/is', $cleaned, $createMatches, PREG_SET_ORDER)) {
        foreach ($createMatches as $cm) {
            $table = strtolower(trim($cm[1], '`'));
            $body = $cm[2];
            if (!isset($schema[$table])) {
                $schema[$table] = [];
            }

            // Split body by commas outside parentheses
            $lines = [];
            $depth = 0;
            $cur = '';
            $len = strlen($body);
            for ($i = 0; $i < $len; $i++) {
                $char = $body[$i];
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $lines[] = trim($cur);
                    $cur = '';
                    continue;
                }
                $cur .= $char;
            }
            if (trim($cur) !== '') {
                $lines[] = trim($cur);
            }

            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $tokens = preg_split('/\s+/', $line);
                if (empty($tokens)) {
                    continue;
                }
                $first = strtolower(trim($tokens[0], '`'));
                if (in_array($first, $skipKeywords, true)) {
                    continue;
                }
                if (preg_match('/^[`]?([a-zA-Z0-9_]+)[`]?/i', $line, $colMatch)) {
                    $schema[$table][strtolower($colMatch[1])] = true;
                }
            }
        }
    }

    // 2. ALTER TABLE `tbl` ADD [COLUMN] [IF NOT EXISTS] `col`
    if (preg_match_all('/ALTER\s+TABLE\s+([`\w]+)\s+(.*?);/is', $cleaned, $alterMatches, PREG_SET_ORDER)) {
        foreach ($alterMatches as $am) {
            $table = strtolower(trim($am[1], '`'));
            $rest = $am[2];
            if (preg_match_all('/ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?([`\w]+)/i', $rest, $colMatches)) {
                foreach ($colMatches[1] as $col) {
                    $colName = strtolower(trim($col, '`'));
                    if (!in_array($colName, $skipKeywords, true)) {
                        add_schema_column($schema, $table, $colName);
                    }
                }
            }
        }
    }

    // 3. Dynamic PREPARE strings in raw file (e.g. SET @sql := 'ALTER TABLE users ADD COLUMN ...')
    if (preg_match_all('/ALTER\s+TABLE\s+([`\w]+)\s+ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?([`\w]+)/i', $raw, $dynMatches, PREG_SET_ORDER)) {
        foreach ($dynMatches as $dm) {
            $table = strtolower(trim($dm[1], '`'));
            $colName = strtolower(trim($dm[2], '`'));
            if (!in_array($colName, $skipKeywords, true)) {
                add_schema_column($schema, $table, $colName);
            }
        }
    }
}

$allTables = array_keys($schema);
sort($allTables);

echo "=== AaZa HRMS Schema Checker ===\n";
echo "Loaded " . count($allTables) . " tables from schema & phase SQL migrations.\n";

// 2. Scan PHP files
$phpFiles = [];
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iter as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $relPath = str_replace('\\', '/', substr($path, strlen($rootDir) + 1));
    
    // Ignore vendor, scratch, .git, and tests directory
    if (str_starts_with($relPath, 'vendor/') ||
        str_starts_with($relPath, '.git/') ||
        str_starts_with($relPath, 'scratch/') ||
        str_starts_with($relPath, 'tests/')) {
        continue;
    }

    $phpFiles[] = [$path, $relPath];
}

$errors = [];
$pseudoTables = ['select', 'information_schema', 'dual', 'set', 'where', 'values'];
$sqlKeywords = [
    'on', 'where', 'join', 'left', 'right', 'inner', 'outer', 'set', 'group', 'order',
    'using', 'and', 'or', 'limit', 'as', 'asc', 'desc', 'by', 'having', 'into', 'from'
];

foreach ($phpFiles as [$absPath, $relPath]) {
    $code = (string)file_get_contents($absPath);
    $tokens = token_get_all($code);

    $sqlStrings = [];
    foreach ($tokens as $token) {
        if (is_array($token)) {
            $id = $token[0];
            $text = $token[1];
            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                // If it contains SQL keywords
                if (preg_match('/\b(SELECT\s+.*?\s+FROM|INSERT\s+INTO|UPDATE\s+.*?SET|DELETE\s+FROM)\b/is', $text)) {
                    // Unquote if single or double quoted
                    $unquoted = $text;
                    if ((str_starts_with($unquoted, "'") && str_ends_with($unquoted, "'")) ||
                        (str_starts_with($unquoted, '"') && str_ends_with($unquoted, '"'))) {
                        $unquoted = substr($unquoted, 1, -1);
                    }
                    $sqlStrings[] = $unquoted;
                }
            }
        }
    }

    // Also look for multi-line queries or template queries in raw code if any
    if (empty($sqlStrings)) {
        if (preg_match_all('/["\']((?:SELECT\s+.*?\s+FROM|INSERT\s+INTO|UPDATE\s+.*?SET|DELETE\s+FROM).*?)["\']/is', $code, $rawMatches)) {
            $sqlStrings = $rawMatches[1];
        }
    }

    foreach ($sqlStrings as $query) {
        // Build table alias map for this specific query
        $aliasMap = [];
        if (preg_match_all('/\b(?:FROM|JOIN)\s+[`]?([a-zA-Z0-9_]+)[`]?\s+(?:AS\s+)?([a-zA-Z0-9_]+)\b/i', $query, $aliasMatches, PREG_SET_ORDER)) {
            foreach ($aliasMatches as $am) {
                $srcTable = strtolower($am[1]);
                $alias = strtolower($am[2]);
                if (isset($schema[$srcTable]) && !in_array($alias, $sqlKeywords, true)) {
                    $aliasMap[$alias] = $srcTable;
                }
            }
        }

        // 1. Check table names after FROM, JOIN, INTO, UPDATE (not DUPLICATE KEY UPDATE, not :from parameter)
        // FROM table
        if (preg_match_all('/(?<!:)\bFROM\s+[`]?([a-zA-Z0-9_]+)[`]?/i', $query, $fromMatches)) {
            foreach ($fromMatches[1] as $tbl) {
                $t = strtolower($tbl);
                if (!in_array($t, $pseudoTables, true) && !isset($schema[$t])) {
                    $errors[] = "[$relPath] Unknown table '{$t}' after FROM";
                }
            }
        }

        // JOIN table
        if (preg_match_all('/(?<!:)\bJOIN\s+[`]?([a-zA-Z0-9_]+)[`]?/i', $query, $joinMatches)) {
            foreach ($joinMatches[1] as $tbl) {
                $t = strtolower($tbl);
                if (!in_array($t, $pseudoTables, true) && !isset($schema[$t])) {
                    $errors[] = "[$relPath] Unknown table '{$t}' after JOIN";
                }
            }
        }

        // INSERT INTO table
        if (preg_match_all('/(?<!:)\bINSERT\s+(?:IGNORE\s+)?INTO\s+[`]?([a-zA-Z0-9_]+)[`]?/i', $query, $intoMatches)) {
            foreach ($intoMatches[1] as $tbl) {
                $t = strtolower($tbl);
                if (!in_array($t, $pseudoTables, true) && !isset($schema[$t])) {
                    $errors[] = "[$relPath] Unknown table '{$t}' after INSERT INTO";
                }
            }
        }

        // UPDATE table (exclude ON DUPLICATE KEY UPDATE)
        if (preg_match_all('/(?<!DUPLICATE\sKEY\s)(?<!:)\bUPDATE\s+[`]?([a-zA-Z0-9_]+)[`]?/i', $query, $updateMatches)) {
            foreach ($updateMatches[1] as $tbl) {
                $t = strtolower($tbl);
                if (!in_array($t, $pseudoTables, true) && !isset($schema[$t])) {
                    $errors[] = "[$relPath] Unknown table '{$t}' after UPDATE";
                }
            }
        }

        // 2. Check INSERT INTO table (col1, col2, ...) column lists
        if (preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+[`]?([a-zA-Z0-9_]+)[`]?\s*\((.*?)\)/is', $query, $insertColMatches, PREG_SET_ORDER)) {
            foreach ($insertColMatches as $icm) {
                $table = strtolower($icm[1]);
                $colStr = $icm[2];
                if (isset($schema[$table])) {
                    $cols = explode(',', $colStr);
                    foreach ($cols as $c) {
                        $c = strtolower(trim($c, "` \t\r\n"));
                        if ($c === '' || str_starts_with($c, '$') || str_starts_with($c, ':')) {
                            continue;
                        }
                        if (!isset($schema[$table][$c])) {
                            $errors[] = "[$relPath] Unknown column '{$c}' in INSERT INTO {$table}";
                        }
                    }
                }
            }
        }

        // 3. Check UPDATE table SET col1 = ..., col2 = ...
        if (preg_match_all('/(?<!DUPLICATE\sKEY\s)\bUPDATE\s+[`]?([a-zA-Z0-9_]+)[`]?\s+(?:AS\s+)?(?:[a-zA-Z0-9_]+\s+)?SET\s+(.*?)(?:\s+WHERE|$)/is', $query, $updateSetMatches, PREG_SET_ORDER)) {
            foreach ($updateSetMatches as $usm) {
                $table = strtolower($usm[1]);
                $setBody = $usm[2];
                if (isset($schema[$table])) {
                    if (preg_match_all('/[`]?([a-zA-Z0-9_]+)[`]?\s*=/i', $setBody, $scMatches)) {
                        foreach ($scMatches[1] as $c) {
                            $c = strtolower($c);
                            if (isset($schema[$c]) || isset($aliasMap[$c]) || in_array($c, $sqlKeywords, true)) {
                                continue;
                            }
                            if (!isset($schema[$table][$c])) {
                                $errors[] = "[$relPath] Unknown column '{$c}' in UPDATE {$table} SET";
                            }
                        }
                    }
                }
            }
        }

        // 4. Check alias.column and table.column references
        if (preg_match_all('/\b([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)\b/i', $query, $colRefMatches, PREG_SET_ORDER)) {
            foreach ($colRefMatches as $crm) {
                $prefix = strtolower($crm[1]);
                $col = strtolower($crm[2]);

                $targetTable = null;
                if (isset($schema[$prefix])) {
                    $targetTable = $prefix;
                } elseif (isset($aliasMap[$prefix])) {
                    $targetTable = $aliasMap[$prefix];
                }

                if ($targetTable !== null) {
                    // Ignore SQL wildcard or standard expressions
                    if ($col === '*' || in_array($col, ['count', 'sum', 'min', 'max', 'avg'], true)) {
                        continue;
                    }
                    if (!isset($schema[$targetTable][$col])) {
                        $errors[] = "[$relPath] Unknown column '{$col}' on table/alias '{$prefix}' ({$targetTable})";
                    }
                }
            }
        }
    }
}

echo "Scanned " . count($phpFiles) . " application PHP files.\n";

if (!empty($errors)) {
    echo "\n[ERROR] Schema verification failed with " . count($errors) . " issue(s):\n";
    foreach ($errors as $err) {
        echo "  - {$err}\n";
    }
    exit(1);
}

echo "\n[OK] Schema verification clean. All tables and columns match migration definitions.\n";
exit(0);
