<?php

namespace Kcs\ApiConnectorBundle\Uri;

use Psr\Http\Message\UriInterface;

/**
 * Extension of BaseUri implementation to deal with query
 * and fragment parts of the URI
 *
 * @link https://github.com/phly/http This class is based upon
 *     Matthew Weier O'Phinney's URI implementation in phly/http.
 */
class Uri extends BaseUri
{
    /** @var string Uri query string. */
    private $query = '';

    /** @var string Uri fragment. */
    private $fragment = '';

    /**
     * Create a new URI with a specific query string value removed.
     *
     * Any existing query string values that exactly match the provided key are
     * removed.
     *
     * Note: this function will convert "=" to "%3D" and "&" to "%26".
     *
     * @param UriInterface $uri URI to use as a base.
     * @param string       $key Query string key value pair to remove.
     *
     * @return UriInterface
     */
    public static function withoutQueryValue(UriInterface $uri, $key)
    {
        $current = $uri->getQuery();
        if (!$current) {
            return $uri;
        }

        $result = [];
        foreach (explode('&', $current) as $part) {
            if (explode('=', $part)[0] !== $key) {
                $result[] = $part;
            };
        }

        return $uri->withQuery(implode('&', $result));
    }

    /**
     * Create a new URI with a specific query string value.
     *
     * Any existing query string values that exactly match the provided key are
     * removed and replaced with the given key value pair.
     *
     * Note: this function will convert "=" to "%3D" and "&" to "%26".
     *
     * @param UriInterface $uri URI to use as a base.
     * @param string $key   Key to set.
     * @param string $value Value to set.
     *
     * @return UriInterface
     */
    public static function withQueryValue(UriInterface $uri, $key, $value)
    {
        $current = $uri->getQuery();
        $key = strtr($key, self::$replaceQuery);

        if (!$current) {
            $result = [];
        } else {
            $result = [];
            foreach (explode('&', $current) as $part) {
                if (explode('=', $part)[0] !== $key) {
                    $result[] = $part;
                };
            }
        }

        if ($value !== null) {
            $result[] = $key . '=' . strtr($value, self::$replaceQuery);
        } else {
            $result[] = $key;
        }

        return $uri->withQuery(implode('&', $result));
    }

    /**
     * Create a URI from a hash of parse_url parts.
     *
     * @param array $parts
     *
     * @return self
     */
    public static function fromParts(array $parts)
    {
        $uri = new self();
        $uri->applyParts($parts);
        return $uri;
    }

    public function getQuery()
    {
        return $this->query;
    }

    public function getFragment()
    {
        return $this->fragment;
    }

    public function withQuery($query)
    {
        if (!is_string($query) && !method_exists($query, '__toString')) {
            throw new \InvalidArgumentException(
                'Query string must be a string'
            );
        }

        $query = (string) $query;
        if (substr($query, 0, 1) === '?') {
            $query = substr($query, 1);
        }

        $query = $this->filterQueryAndFragment($query);

        if ($this->query === $query) {
            return $this;
        }

        $new = clone $this;
        $new->query = $query;
        return $new;
    }

    public function withFragment($fragment)
    {
        if (substr($fragment, 0, 1) === '#') {
            $fragment = substr($fragment, 1);
        }

        $fragment = $this->filterQueryAndFragment($fragment);

        if ($this->fragment === $fragment) {
            return $this;
        }

        $new = clone $this;
        $new->fragment = $fragment;
        return $new;
    }

    /**
     * Apply parse_url parts to a URI.
     *
     * @param array $parts Array of parse_url parts to apply.
     */
    protected function applyParts(array $parts)
    {
        parent::applyParts($parts);

        $this->query = isset($parts['query'])
            ? $this->filterQueryAndFragment($parts['query'])
            : '';
        $this->fragment = isset($parts['fragment'])
            ? $this->filterQueryAndFragment($parts['fragment'])
            : '';
    }

    /**
     * Filters the query string or fragment of a URI.
     *
     * @param $str
     *
     * @return string
     */
    private function filterQueryAndFragment($str)
    {
        return preg_replace_callback(
            '/(?:[^' . self::$charUnreserved . self::$charSubDelims . '%:@\/\?]+|%(?![A-Fa-f0-9]{2}))/',
            [$this, 'rawurlencodeMatchZero'],
            $str
        );
    }

    private function rawurlencodeMatchZero(array $match)
    {
        return rawurlencode($match[0]);
    }
}
