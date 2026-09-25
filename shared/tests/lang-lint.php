<?php
/**
 * Language file lint for Joomla extensions.
 *
 * Usage: php shared/tests/lang-lint.php <extension-directory> [<extension-directory> ...]
 *
 * Checks every *.ini below a `language/<tag>/` directory of the given extension:
 *   - every line is empty, a comment, or KEY="value"
 *   - the file parses the way Joomla parses it (parse_ini_string, INI_SCANNER_RAW)
 *   - no unescaped double quote inside a value (Joomla drops the text after it)
 *   - the same keys exist as in the en-GB counterpart
 *   - the same printf placeholders are used as in the en-GB counterpart
 *   - de-DE: no "ß" (Swiss High German) and no ae/oe/ue spellings of common words
 *   - fr-FR: no unaccented spellings of common French words
 *
 * And the usage of the keys by the extension's own code (PHP, JavaScript, XML
 * outside language/ and tests/), so a further language can be added by adding
 * language files only:
 *   - every key of the extension's own prefixes that the code names is defined
 *     in every language the extension ships
 *   - every defined key is named by the code, derived from a Joomla
 *     `langConstPrefix` (<prefix>_TITLE, <prefix>_DESC), or listed in
 *     tests/language-keys-for-template-overrides.txt (keys provided for site
 *     template overrides that no code of the extension uses itself)
 *   - no key of an own prefix is assembled at runtime ('PREFIX_' . $x or
 *     'PREFIX_' + x): keys are written out in full so both checks above see them
 *   - no "ß" in text the code hard-codes, so the Swiss spelling holds outside
 *     the language files too (marker `lang-lint-allow-eszett` exempts a line)
 *
 * Exits with 1 when any problem is found.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php lang-lint.php <extension-directory> [...]\n");
    exit(2);
}

const REFERENCE_TAG = 'en-GB';

// Word lists are deliberately limited to words that are unambiguous in their
// replacement spelling. Matching is case-insensitive and on whole words.
const GERMAN_REPLACEMENT_WORDS = [
    'fuer', 'ueber', 'koennen', 'koennte', 'moechten', 'muessen', 'waehlen', 'gewaehlt',
    'oberflaeche', 'loeschen', 'loeschung', 'geloescht', 'pruefen', 'geprueft', 'pruefung',
    'aendern', 'geaendert', 'aenderung', 'aenderungen', 'schluessel', 'groesse', 'zurueck',
    'verfuegbar', 'gueltig', 'ungueltig', 'bestaetigen', 'bestaetigt', 'bestaetigung',
    'eintraege', 'uebersicht', 'spaeter', 'naechste', 'waehrung', 'menue', 'haeufig',
    'enthaelt', 'erhaelt', 'laender', 'oeffnen', 'geoeffnet', 'hinzufuegen', 'hinzugefuegt',
    'datensaetze', 'ausfuehren', 'ausgefuehrt', 'benoetigt', 'moeglich', 'unmoeglich',
    'gehoert', 'waehrend', 'fuehren', 'durchfuehren', 'aktivitaet', 'schaltflaeche',
];

const FRENCH_UNACCENTED_WORDS = [
    'donnees', 'confidentialite', 'planifiee', 'planifiees', 'periode', 'apres', 'parametres',
    'parametre', 'creer', 'verifier', 'verifie', 'tache', 'taches', 'reel', 'reelle', 'recente',
    'expiree', 'expirees', 'enregistrees', 'anonymisees', 'perpetuelles', 'detecter', 'reactivation',
    'completement', 'selectionner', 'securite', 'prefixe', 'executer', 'inserer', 'deja', 'etre',
    'systeme', 'echec', 'desactive', 'desactiver', 'telecharger', 'requete', 'reponse', 'modele',
    'element', 'elements', 'generale', 'precedent', 'cree', 'creee', 'reussi', 'reussie',
    'problemes', 'probleme', 'acces',
];

$errors = [];

$addError = static function (string $file, int $line, string $message) use (&$errors): void {
    $errors[] = sprintf('%s:%d: %s', $file, $line, $message);
};

/**
 * @return array<string, array{value:string, line:int}>
 */
function lint_parse_file(string $path, callable $addError): array
{
    $content = file_get_contents($path);
    $entries = [];

    if ($content === false) {
        $addError($path, 0, 'cannot read file');
        return $entries;
    }

    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }

    foreach (preg_split('/\R/', $content) as $index => $rawLine) {
        $lineNo = $index + 1;
        $line   = rtrim($rawLine);

        if (trim($line) === '' || str_starts_with(ltrim($line), ';')) {
            continue;
        }

        if (!preg_match('/^([A-Z0-9_.\-]+)="(.*)"$/', $line, $match)) {
            $addError($path, $lineNo, 'line is not of the form KEY="value"');
            continue;
        }

        [$key, $value] = [$match[1], $match[2]];

        if (preg_match('/(?<!\\\\)"/', $value)) {
            $addError($path, $lineNo, "unescaped double quote inside the value of $key (use single quotes in HTML attributes)");
        }

        if (isset($entries[$key])) {
            $addError($path, $lineNo, "duplicate key $key (first on line {$entries[$key]['line']})");
        }

        $entries[$key] = ['value' => $value, 'line' => $lineNo];
    }

    $parsed = @parse_ini_string(str_replace('_QQ_', '"\""', $content), false, INI_SCANNER_RAW);

    if ($parsed === false) {
        $addError($path, 0, 'parse_ini_string() fails (Joomla cannot load this file)');
    } else {
        foreach ($entries as $key => $entry) {
            if (!array_key_exists($key, $parsed)) {
                $addError($path, $entry['line'], "key $key is lost when Joomla parses the file");
                continue;
            }

            $expected = stripcslashes($entry['value']);

            if ((string) $parsed[$key] !== $entry['value'] && (string) $parsed[$key] !== $expected) {
                $addError($path, $entry['line'], "value of $key is altered by the Joomla parser");
            }
        }
    }

    return $entries;
}

/**
 * Check that the extension's code and its language files agree (see the file
 * header). $definedByTag: tag => key => [path, line] of the definition.
 *
 * @param array<string, array<string, array{0:string, 1:int}>> $definedByTag
 */
/**
 * The extension's own code files: PHP, JavaScript and XML that ship with the
 * extension.
 *
 * Skipped on purpose, so the checks below never fire on something the
 * extension does not write itself or does not ship:
 *   - `language/`   the language files, which have their own checks
 *   - `vendor/`, `node_modules/`   third-party code, taken over unchanged
 *   - `tests…/`     test scripts, fixtures and frozen comparison files, which
 *                   are not part of the package and deliberately contain the
 *                   very spellings the checks look for
 *
 * @return string[]  Absolute paths with forward slashes, sorted.
 */
function lint_code_files(string $extensionDir): array
{
    $files    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extensionDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        $path = str_replace('\\', '/', $fileInfo->getPathname());

        if (!preg_match('/\.(php|js|xml)$/', $path)
            || preg_match('#/(language|vendor|node_modules|tests[^/]*)/#', $path)
        ) {
            continue;
        }

        $files[] = $path;
    }

    sort($files);

    return $files;
}

/**
 * Swiss High German in text the extension hard-codes.
 *
 * The language files are already checked for "ß"; a German string written
 * straight into PHP, JavaScript or XML (a label, a description, a message, an
 * XML `label=` or `description=`) used to slip past. Any "ß" in a shipped code
 * file is reported, because the house spelling has no legitimate use for it.
 *
 * A line that genuinely must keep the character — third-party text that may not
 * be altered — carries the marker `lang-lint-allow-eszett` in a comment on the
 * same line.
 */
function lint_swiss_spelling(string $extensionDir, callable $addError): void
{
    foreach (lint_code_files($extensionDir) as $path) {
        foreach (preg_split('/\R/', (string) file_get_contents($path)) as $index => $line) {
            if (!str_contains($line, 'ß') || str_contains($line, 'lang-lint-allow-eszett')) {
                continue;
            }

            $addError(
                $path,
                $index + 1,
                "hard-coded text contains 'ß' (use 'ss' for Swiss High German, or move the text to a language file)"
            );
        }
    }
}

function lint_usage(string $extensionDir, array $definedByTag, array $fileNames, callable $addError): void
{
    if ($definedByTag === []) {
        return;
    }

    // Own prefixes from the language file names: plg_privacy_j2commerce(.sys).ini
    // -> PLG_PRIVACY_J2COMMERCE. Keys of these prefixes must be defined when named.
    $prefixes = [];
    foreach ($fileNames as $name) {
        $prefixes[strtoupper(preg_replace('/(\.sys)?\.ini$/', '', $name))] = true;
    }
    $prefixes = array_keys($prefixes);

    $isOwn = static function (string $key) use ($prefixes): bool {
        foreach ($prefixes as $prefix) {
            if ($key === $prefix || str_starts_with($key, $prefix . '_')) {
                return true;
            }
        }

        return false;
    };

    $tokens   = [];   // key-shaped token => [path, line] of first use
    $named    = [];   // own-prefix key literal => [path, line]
    $derived  = [];   // keys Joomla derives from a langConstPrefix
    $constPrefixes = [];   // the langConstPrefix values themselves (not keys)

    foreach (lint_code_files($extensionDir) as $path) {
        $content = (string) file_get_contents($path);

        foreach (preg_split('/\R/', $content) as $index => $line) {
            $where = [$path, $index + 1];

            if (preg_match_all('/[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+/', $line, $m)) {
                foreach ($m[0] as $token) {
                    $tokens[$token] ??= $where;
                }
            }

            // Quoted literals and XML attribute or element values that name a key.
            if (preg_match_all('/[\'">]([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*)[\'"<]/', $line, $m)) {
                foreach ($m[1] as $literal) {
                    if ($isOwn($literal)) {
                        $named[$literal] ??= $where;
                    }
                }
            }

            if (preg_match_all("/['\"]([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*_)['\"]\s*[.+]/", $line, $m)) {
                foreach ($m[1] as $fragment) {
                    if ($isOwn(rtrim($fragment, '_'))) {
                        $addError($path, $index + 1, "language key assembled at runtime from '$fragment' (write the keys out in full)");
                    }
                }
            }

            if (preg_match("/['\"]langConstPrefix['\"]\s*=>\s*['\"]([A-Z0-9_]+)['\"]/", $line, $m)) {
                $derived[$m[1] . '_TITLE'] = true;
                $derived[$m[1] . '_DESC']  = true;
                $constPrefixes[$m[1]]      = true;
            }
        }
    }

    // Keys the extension provides for site template overrides.
    $forOverrides = [];
    $listFile     = $extensionDir . '/tests/language-keys-for-template-overrides.txt';

    if (is_file($listFile)) {
        foreach (preg_split('/\R/', (string) file_get_contents($listFile)) as $line) {
            $line = trim($line);

            if ($line !== '' && $line[0] !== '#') {
                $forOverrides[$line] = true;
            }
        }
    }

    $allDefined = [];
    foreach ($definedByTag as $keys) {
        $allDefined += $keys;
    }

    // Named but not defined in every language.
    foreach ($named as $key => $where) {
        if (isset($constPrefixes[$key]) || isset($derived[$key])) {
            continue;   // checked below as <prefix>_TITLE and <prefix>_DESC
        }

        foreach ($definedByTag as $tag => $keys) {
            if (!isset($keys[$key])) {
                $addError($where[0], $where[1], "$key is used here but not defined in $tag");
            }
        }
    }

    foreach (array_keys($derived) as $key) {
        foreach ($definedByTag as $tag => $keys) {
            if (!isset($keys[$key])) {
                $addError($extensionDir, 0, "$key (derived from langConstPrefix) is not defined in $tag");
            }
        }
    }

    // Defined but used nowhere.
    foreach ($allDefined as $key => $where) {
        if (isset($tokens[$key]) || isset($derived[$key]) || isset($forOverrides[$key])) {
            continue;
        }

        $addError($where[0], $where[1], "$key is defined but no code uses it (remove it, or list it in tests/language-keys-for-template-overrides.txt)");
    }

    foreach (array_keys($forOverrides) as $key) {
        if (!isset($allDefined[$key])) {
            $addError($listFile, 0, "$key is listed for template overrides but not defined");
        }
    }
}

function lint_placeholders(string $value): array
{
    preg_match_all('/%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX%]/', $value, $m);
    $list = array_values(array_filter($m[0], static fn ($p) => $p !== '%%'));
    sort($list);

    return $list;
}

foreach (array_slice($argv, 1) as $extensionDir) {
    $extensionDir = rtrim($extensionDir, '/\\');

    if (!is_dir($extensionDir)) {
        fwrite(STDERR, "Not a directory: $extensionDir\n");
        exit(2);
    }

    $groups   = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extensionDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        $path = str_replace('\\', '/', $fileInfo->getPathname());

        if (!str_ends_with($path, '.ini') || preg_match('#/(vendor|node_modules|tests[^/]*)/#', $path)) {
            continue;
        }

        if (!preg_match('#^(.*)/language/([a-z]{2}-[A-Z]{2})/(?:[a-z]{2}-[A-Z]{2}\.)?([^/]+)$#', $path, $m)) {
            continue;
        }

        $groups[$m[1] . '|' . $m[3]][$m[2]] = $path;
    }

    ksort($groups);

    $definedByTag = [];   // tag => key => [path, line], over all files of the extension
    $fileNames    = [];

    foreach ($groups as $group => $files) {
        $parsed = [];

        foreach ($files as $tag => $path) {
            $parsed[$tag] = lint_parse_file($path, $addError);

            foreach ($parsed[$tag] as $key => $entry) {
                $definedByTag[$tag][$key] ??= [$path, $entry['line']];
            }
        }

        $fileNames[] = explode('|', $group)[1];

        if (!isset($parsed[REFERENCE_TAG])) {
            $addError(reset($files), 0, 'no en-GB counterpart for this language file');
            continue;
        }

        $reference = $parsed[REFERENCE_TAG];

        foreach ($parsed as $tag => $entries) {
            $path = $files[$tag];

            if ($tag !== REFERENCE_TAG) {
                foreach (array_diff_key($reference, $entries) as $key => $entry) {
                    $addError($path, 0, "missing key $key (present in en-GB)");
                }

                foreach (array_diff_key($entries, $reference) as $key => $entry) {
                    $addError($path, $entry['line'], "extra key $key (not present in en-GB)");
                }

                foreach (array_intersect_key($entries, $reference) as $key => $entry) {
                    if (lint_placeholders($entry['value']) !== lint_placeholders($reference[$key]['value'])) {
                        $addError($path, $entry['line'], "placeholders of $key differ from en-GB");
                    }
                }
            }

            foreach ($entries as $key => $entry) {
                $value = $entry['value'];

                if ($tag === 'de-DE') {
                    if (str_contains($value, 'ß')) {
                        $addError($path, $entry['line'], "$key contains 'ß' (use 'ss' for Swiss High German)");
                    }

                    foreach (GERMAN_REPLACEMENT_WORDS as $word) {
                        if (preg_match('/(?<![\p{L}])' . preg_quote($word, '/') . '(?![\p{L}])/iu', $value)) {
                            $addError($path, $entry['line'], "$key uses the spelling '$word' instead of an umlaut");
                        }
                    }
                }

                if ($tag === 'fr-FR') {
                    foreach (FRENCH_UNACCENTED_WORDS as $word) {
                        if (preg_match('/(?<![\p{L}])' . preg_quote($word, '/') . '(?![\p{L}])/iu', $value)) {
                            $addError($path, $entry['line'], "$key contains the unaccented word '$word'");
                        }
                    }
                }
            }
        }
    }

    lint_usage($extensionDir, $definedByTag, array_unique($fileNames), $addError);
    lint_swiss_spelling($extensionDir, $addError);
}

if ($errors) {
    echo implode("\n", $errors) . "\n";
    echo count($errors) . " language problem(s) found\n";
    exit(1);
}

echo "Language files OK\n";
exit(0);
