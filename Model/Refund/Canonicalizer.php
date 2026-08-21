<?php

namespace Emipro\Apichange\Model\Refund;

/**
 * Deterministic serialisation used by every hash of this module.
 *
 * Two different PHP processes, on two different Magento nodes, running two
 * different PHP versions, must produce byte identical output for equivalent
 * input. That rules out json_encode() on raw floats, whose textual form depends
 * on serialize_precision, so every numeric leaf is normalised to a fixed scale
 * decimal string first and every map is key sorted.
 */
class Canonicalizer
{
    /**
     * Normalise then JSON encode.
     *
     * @param mixed $value
     * @return string
     */
    public function encode($value)
    {
        $json = json_encode(
            $this->normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return $json === false ? '' : $json;
    }

    /**
     * Canonical sha256 of a structure, prefixed with its algorithm.
     *
     * @param mixed $value
     * @return string
     */
    public function hash($value)
    {
        return 'sha256:' . hash('sha256', $this->encode($value));
    }

    /**
     * Recursively normalise a structure.
     *
     * Lists keep their order, maps are sorted by key, floats and integers
     * become fixed scale decimal strings, booleans become "true"/"false" and
     * null stays null so that "absent" and "zero" never collide.
     *
     * @param mixed $value
     * @return mixed
     */
    public function normalize($value)
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return $this->scalar($value);
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            if ($this->isList($value)) {
                $out = [];
                foreach ($value as $item) {
                    $out[] = $this->normalize($item);
                }

                return $out;
            }

            $keys = array_map('strval', array_keys($value));
            sort($keys, SORT_STRING);
            $out = [];
            foreach ($keys as $key) {
                $out[$key] = $this->normalize($value[$key]);
            }

            // Cast to object so that a map whose keys happen to be "0", "1", ...
            // is never re-encoded as a JSON array.
            return (object) $out;
        }

        // Objects and resources have no stable textual form: refuse silently
        // rather than hashing an address.
        return null;
    }

    /**
     * Fixed scale decimal representation of a number.
     *
     * @param int|float $value
     * @return string
     */
    public function scalar($value)
    {
        if (!is_finite((float) $value)) {
            return '0.' . str_repeat('0', Contract::SCALE);
        }

        $formatted = number_format((float) $value, Contract::SCALE, '.', '');

        // number_format() can emit "-0.0000"; collapse every signed zero.
        if (ltrim($formatted, '-0.') === '') {
            $formatted = number_format(0, Contract::SCALE, '.', '');
        }

        return $formatted;
    }

    /**
     * Fixed scale decimal representation of a nullable number.
     *
     * @param int|float|string|null $value
     * @return string|null
     */
    public function nullableScalar($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->scalar((float) $value);
    }

    /**
     * array_is_list() equivalent that also works on PHP 7.4.
     *
     * @param array $value
     * @return bool
     */
    private function isList(array $value)
    {
        $expected = 0;
        foreach ($value as $key => $unused) {
            if ($key !== $expected++) {
                return false;
            }
        }

        return true;
    }
}
