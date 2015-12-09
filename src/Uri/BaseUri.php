<?php
namespace Kcs\ApiConnectorBundle\Uri;

use Psr\Http\Message\UriInterface;

/**
 * PSR-7 Base URI implementation.
 * This class will ignore query and fragment parts of the URI
 *
 * @link https://github.com/phly/http This class is based upon
 *     Matthew Weier O'Phinney's URI implementation in phly/http.
 */
class BaseUri implements UriInterface
{
    private static $schemes = [
        'http'  => 80,
        'https' => 443,
    ];

    protected static $charUnreserved = 'a-zA-Z0-9_\-\.~';
    protected static $charSubDelims = '!\$&\'\(\)\*\+,;=';
    protected static $replaceQuery = ['=' => '%3D', '&' => '%26'];

    /** @var string Uri scheme. */
    private $scheme = '';

    /** @var string Uri user info. */
    private $userInfo = '';

    /** @var string Uri host. */
    private $host = '';

    /** @var int|null Uri port. */
    private $port;

    /** @var string Uri path. */
    private $path = '';

    /**
     * @param string $uri URI to parse and wrap.
     */
    public function __construct($uri = '')
    {
        if ($uri) {
            $parts = parse_url($uri);
            if ($parts === false) {
                throw new \InvalidArgumentException("Unable to parse URI: $uri");
            }
            $this->applyParts($parts);
        }
    }

    public function __toString()
    {
        return self::createUriString(
            $this->getScheme(),
            $this->getAuthority(),
            $this->getPath(),
            $this->getQuery(),
            $this->getFragment()
        );
    }

    /**
     * Removes dot segments from a path and returns the new path.
     *
     * @param string $path
     *
     * @return string
     * @link http://tools.ietf.org/html/rfc3986#section-5.2.4
     */
    public static function removeDotSegments($path)
    {
        static $noopPaths = ['' => true, '/' => true, '*' => true];
        static $ignoreSegments = ['.' => true, '..' => true];

        if (isset($noopPaths[$path])) {
            return $path;
        }

        $results = [];
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment == '..') {
                array_pop($results);
            } elseif (!isset($ignoreSegments[$segment])) {
                $results[] = $segment;
            }
        }

        $newPath = implode('/', $results);
        // Add the leading slash if necessary
        if (substr($path, 0, 1) === '/' &&
            substr($newPath, 0, 1) !== '/'
        ) {
            $newPath = '/' . $newPath;
        }

        // Add the trailing slash if necessary
        if ($newPath != '/' && isset($ignoreSegments[end($segments)])) {
            $newPath .= '/';
        }

        return $newPath;
    }

    /**
     * Resolve a base URI with a relative URI and return a new URI.
     *
     * @param UriInterface $base Base URI
     * @param UriInterface $rel  Relative URI
     *
     * @return UriInterface
     */
    public static function resolve(UriInterface $base, UriInterface $rel)
    {
        // Return the relative uri as-is if it has a scheme.
        if ($rel->getScheme()) {
            return $rel->withPath(static::removeDotSegments($rel->getPath()));
        }

        $relParts = [
            'scheme'    => $rel->getScheme(),
            'authority' => $rel->getAuthority(),
            'path'      => $rel->getPath(),
            'query'     => $rel->getQuery(),
            'fragment'  => $rel->getFragment()
        ];

        $parts = [
            'scheme'    => $base->getScheme(),
            'authority' => $base->getAuthority(),
            'path'      => $base->getPath(),
            'query'     => $base->getQuery(),
            'fragment'  => $base->getFragment()
        ];

        if (!empty($relParts['authority'])) {
            $parts['authority'] = $relParts['authority'];
            $parts['path'] = self::removeDotSegments($relParts['path']);
            $parts['query'] = $relParts['query'];
            $parts['fragment'] = $relParts['fragment'];
        } elseif (!empty($relParts['path'])) {
            if (substr($relParts['path'], 0, 1) == '/') {
                $parts['path'] = self::removeDotSegments($relParts['path']);
                $parts['query'] = $relParts['query'];
                $parts['fragment'] = $relParts['fragment'];
            } else {
                if (!empty($parts['authority']) && empty($parts['path'])) {
                    $mergedPath = '/';
                } else {
                    $mergedPath = substr($parts['path'], 0, strrpos($parts['path'], '/') + 1);
                }
                $parts['path'] = self::removeDotSegments($mergedPath . $relParts['path']);
                $parts['query'] = $relParts['query'];
                $parts['fragment'] = $relParts['fragment'];
            }
        } elseif (!empty($relParts['query'])) {
            $parts['query'] = $relParts['query'];
        } elseif ($relParts['fragment']) {
            $parts['fragment'] = $relParts['fragment'];
        }

        return new Uri(static::createUriString(
            $parts['scheme'],
            $parts['authority'],
            $parts['path'],
            $parts['query'],
            $parts['fragment']
        ));
    }

    public function getScheme()
    {
        return $this->scheme;
    }

    public function getAuthority()
    {
        if (empty($this->host)) {
            return '';
        }

        $authority = $this->host;
        if (!empty($this->userInfo)) {
            $authority = $this->userInfo . '@' . $authority;
        }

        if ($this->isNonStandardPort($this->scheme, $this->host, $this->port)) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo()
    {
        return $this->userInfo;
    }

    public function getHost()
    {
        return $this->host;
    }

    public function getPort()
    {
        return $this->port;
    }

    public function getPath()
    {
        return ! $this->path ? '' : $this->path;
    }

    public function getQuery()
    {
        return '';
    }

    public function getFragment()
    {
        return '';
    }

    public function withScheme($scheme)
    {
        $scheme = $this->filterScheme($scheme);

        if ($this->scheme === $scheme) {
            return $this;
        }

        $new = clone $this;
        $new->scheme = $scheme;
        $new->port = $new->filterPort($new->scheme, $new->host, $new->port);
        return $new;
    }

    public function withUserInfo($user, $password = null)
    {
        $info = $user;
        if ($password) {
            $info .= ':' . $password;
        }

        if ($this->userInfo === $info) {
            return $this;
        }

        $new = clone $this;
        $new->userInfo = $info;
        return $new;
    }

    public function withHost($host)
    {
        if ($this->host === $host) {
            return $this;
        }

        $new = clone $this;
        $new->host = $host;
        return $new;
    }

    public function withPort($port)
    {
        $port = $this->filterPort($this->scheme, $this->host, $port);

        if ($this->port === $port) {
            return $this;
        }

        $new = clone $this;
        $new->port = $port;
        return $new;
    }

    public function withPath($path)
    {
        if (!is_string($path)) {
            throw new \InvalidArgumentException(
                'Invalid path provided; must be a string'
            );
        }

        $path = $this->filterPath($path);

        if ($this->path === $path) {
            return $this;
        }

        $new = clone $this;
        $new->path = $path;
        return $new;
    }

    public function withQuery($query)
    {
        return $this;
    }

    public function withFragment($fragment)
    {
        return $this;
    }

    /**
     * Apply parse_url parts to a URI.
     *
     * @param array $parts Array of parse_url parts to apply.
     */
    protected function applyParts(array $parts)
    {
        $this->scheme = isset($parts['scheme'])
            ? $this->filterScheme($parts['scheme'])
            : '';
        $this->userInfo = isset($parts['user']) ? $parts['user'] : '';
        $this->host = isset($parts['host']) ? $parts['host'] : '';
        $this->port = !empty($parts['port'])
            ? $this->filterPort($this->scheme, $this->host, $parts['port'])
            : null;
        $this->path = isset($parts['path'])
            ? $this->filterPath($parts['path'])
            : '';
        if (isset($parts['pass'])) {
            $this->userInfo .= ':' . $parts['pass'];
        }
    }

    /**
     * Create a URI string from its various parts
     *
     * @param string $scheme
     * @param string $authority
     * @param string $path
     * @param string $query
     * @param string $fragment
     * @return string
     */
    private static function createUriString($scheme, $authority, $path, $query, $fragment)
    {
        $uri = '';

        if (!empty($scheme)) {
            $uri .= $scheme . '://';
        }

        if (!empty($authority)) {
            $uri .= $authority;
        }

        if ($path) {
            // Add a leading slash if necessary.
            if ($uri && substr($path, 0, 1) !== '/') {
                $uri .= '/';
            }
            $uri .= $path;
        }

        if ($query) {
            $uri .= '?' . $query;
        }

        if ($fragment) {
            $uri .= '#' . $fragment;
        }

        return $uri;
    }

    /**
     * Is a given port non-standard for the current scheme?
     *
     * @param string $scheme
     * @param string $host
     * @param int $port
     * @return bool
     */
    private static function isNonStandardPort($scheme, $host, $port)
    {
        if (!$scheme && $port) {
            return true;
        }

        if (!$host || !$port) {
            return false;
        }

        return !isset(static::$schemes[$scheme]) || $port !== static::$schemes[$scheme];
    }

    /**
     * @param string $scheme
     *
     * @return string
     */
    private function filterScheme($scheme)
    {
        $scheme = strtolower($scheme);
        $scheme = rtrim($scheme, ':/');

        return $scheme;
    }

    /**
     * @param string $scheme
     * @param string $host
     * @param int $port
     *
     * @return int|null
     *
     * @throws \InvalidArgumentException If the port is invalid.
     */
    private function filterPort($scheme, $host, $port)
    {
        if (null !== $port) {
            $port = (int) $port;
            if (1 > $port || 0xffff < $port) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid port: %d. Must be between 1 and 65535', $port)
                );
            }
        }

        return $this->isNonStandardPort($scheme, $host, $port) ? $port : null;
    }

    /**
     * Filters the path of a URI
     *
     * @param $path
     *
     * @return string
     */
    private function filterPath($path)
    {
        return preg_replace_callback(
            '/(?:[^' . self::$charUnreserved . self::$charSubDelims . ':@\/%]+|%(?![A-Fa-f0-9]{2}))/',
            [$this, 'rawurlencodeMatchZero'],
            $path
        );
    }

    private function rawurlencodeMatchZero(array $match)
    {
        return rawurlencode($match[0]);
    }
}
