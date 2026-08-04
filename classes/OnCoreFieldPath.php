<?php

namespace UKModules\ROCS;

/**
 * Discovers and reads scalar fields in an OnCore API response.
 *
 * Nested object members are written with dots. A list can be read three ways,
 * e.g. for a protocolStaff response:
 *
 *  - []                      every entry, joined with "; ", e.g.
 *                            [].contact.firstName - every staff member.
 *  - principalInvestigator   only the entries playing that role, e.g.
 *                            principalInvestigator.contact.firstName. The
 *                            segment is the entry's role field normalised to
 *                            camelCase, so a mapping keeps meaning the same
 *                            thing on every protocol.
 *  - [staffRole=Principal Investigator]
 *                            the long-hand of the same idea. Nothing generates
 *                            it, but saved mappings using it still resolve.
 *                            Inside it a backslash escapes the next character;
 *                            "]" and "\" in a value must be escaped.
 *
 * Role segments are what keep the module automatic: a field mapped to
 * principalInvestigator.contact.lastName syncs without anyone choosing a person
 * per record. [] stays available for genuinely multi-valued fields, and
 * narrowing one of those to a single entry is a per-record decision made in the
 * adjudication view, which is fed by entries().
 *
 * Keep this in sync with discoverOncoreFields/oncorePathEntries in
 * scripts/scripts.php, which read the same saved paths in the browser.
 */
class OnCoreFieldPath
{
    /**
     * A key whose last word is one of these identifies a record rather than
     * describing it, so it reads poorly as a label. contactId, protocolNo and
     * staff_code are all rejected.
     */
    private static $identifierWords = ['id', 'ids', 'guid', 'uuid', 'key', 'code', 'no', 'num', 'number'];

    /**
     * A key whose last word is one of these describes the role a list element
     * plays and is preferred over any other candidate when labelling.
     */
    private static $preferredWords = ['role', 'type', 'category', 'status', 'name', 'position', 'title'];

    /**
     * Only a key whose last word is one of these may become a path segment.
     * This is deliberately narrower than $preferredWords: "name" describes a
     * person, not the part they play, and firstName must never become a path.
     */
    private static $roleWords = ['role', 'type', 'category', 'status', 'position', 'title'];

    /** Labels longer than this are unwieldy in the adjudication table. */
    const LABEL_MAX_LENGTH = 100;

    /**
     * Normalise a role value into a path segment: "Principal Investigator" and
     * "PRINCIPAL_INVESTIGATOR" both become principalInvestigator. Both sides of
     * a comparison are normalised, so nothing needs escaping.
     */
    public static function roleToken($value)
    {
        if (!is_string($value)) {
            return '';
        }

        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $value);
        $words = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $spaced), static function ($word) {
            return $word !== '';
        }));

        $token = '';
        foreach ($words as $index => $word) {
            $token .= $index === 0 ? strtolower($word) : ucfirst(strtolower($word));
        }

        return $token;
    }

    /**
     * The field whose value names the part each entry of a list plays, or null
     * when the list has no such field. Every entry must carry it as a short
     * non-empty string, so a role segment always resolves the same way.
     */
    public static function roleKey($list)
    {
        if (!self::isList($list) || empty($list)) {
            return null;
        }

        $first = reset($list);
        if (!is_array($first)) {
            return null;
        }

        $best = null;
        $bestScore = null;

        foreach (array_keys($first) as $key) {
            if (is_int($key)) {
                continue;
            }

            $word = self::lastWord($key);
            if (!in_array($word, self::$roleWords, true)) {
                continue;
            }

            $usable = true;
            foreach ($list as $element) {
                if (!is_array($element) || !array_key_exists($key, $element)) {
                    $usable = false;
                    break;
                }

                $value = $element[$key];
                if (!is_string($value) || trim($value) === '' || strlen($value) > self::LABEL_MAX_LENGTH
                    || self::roleToken($value) === '') {
                    $usable = false;
                    break;
                }
            }

            if (!$usable) {
                continue;
            }

            $score = $word === 'role' ? 0 : 1;
            if ($bestScore === null || $score < $bestScore) {
                $best = $key;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Return every scalar/null field path found in an API response.
     */
    public static function discover($data)
    {
        $paths = [];
        self::discoverPaths($data, '', $paths);

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return $paths;
    }

    private static function discoverPaths($value, $path, array &$paths)
    {
        if (self::isList($value)) {
            // Every list offers [] for all entries and, where the entries say
            // what part they play, a segment per role so a mapping can name one
            // without anyone picking a person per record.
            $roleKey = self::roleKey($value);

            foreach ($value as $child) {
                self::discoverPaths($child, $path . '[]', $paths);

                if ($roleKey === null) {
                    continue;
                }

                $token = self::roleToken($child[$roleKey]);
                if ($token === '') {
                    continue;
                }

                self::discoverPaths($child, $path === '' ? $token : $path . '.' . $token, $paths);
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                // A non-sequential array can still carry integer keys; those
                // are positions rather than field names.
                $childPath = is_int($key)
                    ? $path . '[]'
                    : ($path === '' ? (string) $key : $path . '.' . $key);
                self::discoverPaths($child, $childPath, $paths);
            }
            return;
        }

        // null keys are still valid API fields and should be available to map.
        if ($path !== '') {
            $paths[] = $path;
        }
    }

    /**
     * Choose the field that best says which entry of a list is which, so the
     * adjudication view can label "Lovelace" as "Principal Investigator".
     *
     * A usable key is present on every entry, holds a short non-empty string, is
     * not an identifier, and does not read the same on every entry.
     */
    public static function labelKey($list)
    {
        if (!self::isList($list) || count($list) < 2) {
            return null;
        }

        $first = reset($list);
        if (!is_array($first)) {
            return null;
        }

        $best = null;
        $bestScore = null;

        foreach (array_keys($first) as $key) {
            if (is_int($key) || in_array(self::lastWord($key), self::$identifierWords, true)) {
                continue;
            }

            $seen = [];
            $usable = true;

            foreach ($list as $element) {
                if (!is_array($element) || !array_key_exists($key, $element)) {
                    $usable = false;
                    break;
                }

                $value = $element[$key];
                if (!is_string($value) || trim($value) === '' || strlen($value) > self::LABEL_MAX_LENGTH) {
                    $usable = false;
                    break;
                }

                $seen[$value] = true;
            }

            // A key reading the same on every entry tells the two apart no
            // better than no label at all.
            if (!$usable || count($seen) < 2) {
                continue;
            }

            $score = in_array(self::lastWord($key), self::$preferredWords, true) ? 0 : 1;
            if ($bestScore === null || $score < $bestScore) {
                $best = $key;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Last word of a field name, lowercased. Handles camelCase and snake_case,
     * so staffRole, staff_role and StaffRole all yield "role".
     */
    private static function lastWord($key)
    {
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', (string) $key);
        $words = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $spaced), static function ($word) {
            return $word !== '';
        }));

        return empty($words) ? '' : strtolower(end($words));
    }

    /**
     * Read a saved path as the individual values behind it, each tagged with a
     * label saying which entry of the list it came from.
     *
     * Returns a list of ['value' => string, 'label' => string] with blanks
     * dropped and duplicate values collapsed. value() is this joined with "; ",
     * so the mapped value and the adjudication options can never disagree.
     */
    public static function entries($data, $path)
    {
        if (!is_string($path) || $path === '') {
            return [];
        }

        // Preserves existing mappings and supports API keys that contain dots.
        if (is_array($data) && array_key_exists($path, $data)) {
            $value = self::stringify($data[$path]);
            return $value === '' ? [] : [['value' => $value, 'label' => '']];
        }

        // Previous releases stored a top-level field name even when the
        // endpoint returned a list. Treat that legacy form as [].field.
        if (self::isList($data) && !preg_match('/[.\[\]]/', $path)) {
            $path = '[].' . $path;
        }

        $tokens = self::tokens($path);
        if (empty($tokens)) {
            return [];
        }

        $entries = self::entriesAtPath([['value' => $data, 'label' => '']], $tokens, 0);

        $result = [];
        $seen = [];
        foreach ($entries as $entry) {
            $value = self::stringifyScalar($entry['value']);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            // A label repeating the value it sits above says nothing.
            $result[] = [
                'value' => $value,
                'label' => $entry['label'] === $value ? '' : $entry['label'],
            ];
        }

        return $result;
    }

    /**
     * Read a saved field path. Legacy top-level field names are supported.
     */
    public static function value($data, $path)
    {
        return implode('; ', array_column(self::entries($data, $path), 'value'));
    }

    public static function hasValue($value)
    {
        return $value !== null && $value !== '';
    }

    /**
     * Split a path into tokens.
     *
     * Each token is one of:
     *   ['type' => 'key',    'name'  => string]
     *   ['type' => 'all']
     *   ['type' => 'filter', 'key'   => string, 'value' => string]
     */
    private static function tokens($path)
    {
        $tokens = [];
        $buffer = '';
        $length = strlen($path);
        $position = 0;

        $flush = function () use (&$tokens, &$buffer) {
            if ($buffer !== '') {
                $tokens[] = ['type' => 'key', 'name' => $buffer];
                $buffer = '';
            }
        };

        while ($position < $length) {
            $character = $path[$position];

            if ($character === '.') {
                $flush();
                $position++;
                continue;
            }

            if ($character === '[') {
                $flush();

                $contents = '';
                $position++;
                while ($position < $length && $path[$position] !== ']') {
                    if ($path[$position] === '\\' && $position + 1 < $length) {
                        $position++;
                    }
                    $contents .= $path[$position];
                    $position++;
                }
                $position++; // consume "]"

                if ($contents === '') {
                    $tokens[] = ['type' => 'all'];
                } else {
                    $separator = strpos($contents, '=');
                    if ($separator === false) {
                        // Unparseable selector: fall back to every element
                        // rather than silently dropping the mapping.
                        $tokens[] = ['type' => 'all'];
                    } else {
                        $tokens[] = [
                            'type'  => 'filter',
                            'key'   => substr($contents, 0, $separator),
                            'value' => substr($contents, $separator + 1),
                        ];
                    }
                }

                continue;
            }

            $buffer .= $character;
            $position++;
        }

        $flush();

        return $tokens;
    }

    private static function entriesAtPath(array $current, array $tokens, $position)
    {
        if ($position >= count($tokens)) {
            return $current;
        }

        $next = [];
        $token = $tokens[$position];

        foreach ($current as $entry) {
            $value = $entry['value'];

            if ($token['type'] === 'all') {
                if (!is_array($value)) {
                    continue;
                }

                // Label each entry by whichever field best tells the entries of
                // this particular list apart.
                $labelKey = self::labelKey($value);
                foreach ($value as $child) {
                    $part = $labelKey !== null && is_array($child) && array_key_exists($labelKey, $child)
                        ? $child[$labelKey]
                        : '';
                    $next[] = ['value' => $child, 'label' => self::joinLabels($entry['label'], $part)];
                }
            } elseif ($token['type'] === 'filter') {
                if (!is_array($value)) {
                    continue;
                }

                foreach ($value as $child) {
                    if (!is_array($child) || !array_key_exists($token['key'], $child)) {
                        continue;
                    }
                    if (self::stringifyScalar($child[$token['key']]) === $token['value']) {
                        $next[] = ['value' => $child, 'label' => self::joinLabels($entry['label'], $token['value'])];
                    }
                }
            } elseif (self::isList($value)) {
                // A dotted segment against a list names a role rather than a
                // field, e.g. principalInvestigator.contact.lastName. Both sides
                // are normalised, so "Principal Investigator" matches however
                // OnCore happens to punctuate it.
                $roleKey = self::roleKey($value);
                if ($roleKey === null) {
                    continue;
                }

                foreach ($value as $child) {
                    if (!is_array($child) || !array_key_exists($roleKey, $child)) {
                        continue;
                    }
                    if (self::roleToken($child[$roleKey]) === $token['name']) {
                        $next[] = ['value' => $child, 'label' => self::joinLabels($entry['label'], $child[$roleKey])];
                    }
                }
            } elseif (is_array($value) && array_key_exists($token['name'], $value)) {
                $next[] = ['value' => $value[$token['name']], 'label' => $entry['label']];
            }
        }

        return self::entriesAtPath($next, $tokens, $position + 1);
    }

    private static function joinLabels($parent, $part)
    {
        $parts = array_filter([$parent, is_string($part) ? $part : ''], static function ($value) {
            return $value !== '';
        });

        return implode(' / ', $parts);
    }

    private static function stringify($value)
    {
        if (is_array($value)) {
            return json_encode($value) ?: '';
        }

        return self::stringifyScalar($value);
    }

    private static function stringifyScalar($value)
    {
        if ($value === null) {
            return '';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function isList($value)
    {
        return is_array($value) && (empty($value) || array_keys($value) === range(0, count($value) - 1));
    }
}
