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

    foreach ($groups as $group => $files) {
        $parsed = [];

        foreach ($files as $tag => $path) {
            $parsed[$tag] = lint_parse_file($path, $addError);
        }

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
}

if ($errors) {
    echo implode("\n", $errors) . "\n";
    echo count($errors) . " language problem(s) found\n";
    exit(1);
}

echo "Language files OK\n";
exit(0);
