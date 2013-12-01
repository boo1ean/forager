<?php namespace Forager;

use Goutte\Client as BaseClient;
use Auth\AuthInterface;
use Symfony\Component\BrowserKit\History;
use Symfony\Component\BrowserKit\CookieJar;

class Client extends BaseClient
{
	/**
	 * Max number of request attempts
	 */
	const ATTEMPTS = 20;

	protected $cache = array();
	protected $useCache = true;
	protected $logins = array();

	public function __construct(array $server = array(), History $history = null, CookieJar $cookieJar = null) {
		parent::__construct($server, $history, $cookieJar);
		$this->setServerParameters(array(
			'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/28.0.1500.71 Safari/537.36'
		));
	}

	public function useCache($useCache) {
		$this->useCache = $useCache;
	}

	/**
	 * Map fetched data according to given schema
	 * @param array $p
	 * @param.p string $method HTTP method name
	 * @param.p string $url request url
	 * @param.p array $params post request params
	 * @param.p array $extract extract schema
	 * @param.p.extract string $scope scope css selector (row, block, etc.)
	 * @param.p.extract array $map map of propname => scope selector pairs
	 */
	public function grep($p) {
		$key = $this->arrayCacheKey(array_except($p, 'transform'));
		if ($this->hasCache($key)) {
			return $this->getCache($key);
		}

		$params  = empty($p['params']) ? array() : $p['params'];
		$crawler = $this->request($p['method'], $p['url'], $params);

		if (!empty($p['transform']['input'])) {
			$crawler = $p['transform']['input']($this, $crawler);
		}

		$extractMethod = 'text';
		if (!empty($p['extract']['method'])) {
			$extractMethod = $p['extract']['method'];
		}

		$scope  = $p['extract']['scope'];
		$map    = $p['extract']['map'];
		$result = array();

		$rows = $crawler->filter($scope);
		$rows->each(function ($node) use ($map, &$result, $extractMethod) {
			$row = array();
			foreach ($map as $prop => $selector) {
				$attr = null;
				if (strpos($selector, '@') !== false) {
					list($selector, $attr) = explode('@', $selector);
				}

				$val = $node->filter($selector);

				if (count($val)) {
					if ($attr) {
						$val = $val->attr($attr);
					} else {
						$val = $val->{$extractMethod}();
					}
				} else {
					$val = null;
				}

				$row[$prop] = $val;
			}

			$result[] = $row;
		});

		if (!empty($p['transform']['output'])) {
			$result = $p['transform']['output']($result);
		}

		if ($this->useCache) {
			$this->setCache($key, $result);
		}

		return $result;
	}

	/**
	 * Check whether response content has specified string
	 * @param string $string
	 * @return bool
	 */
	public function hasContent($string) {
		return false !== strpos($this->getResponse()->getContent() , $string);
	}

	public function login(AuthInterface $auth, $credentials) {
		if ($this->isLoggedIn($auth, $credentials)) {
			return true;
		} else if ($this->hasCookies($auth)) {
			$auth->logout($this);
		}

		$status = $auth->login($this, $credentials);

		if ($status && $this->useCache) {
			$this->markAsLoggedIn($auth, $credentials);
		}

		return $status;
	}

	/**
	 * Returns server params array
	 * @return array
	 */
	public function getServerParameters() {
		return $this->server;
	}

	/**
	 * Check if client is logged in to specified network with given credentials
	 *
	 * @param App\Ever\Http\Auth\AuthInterface $auth
	 * @param array $credentials auth credentials
	 * @return bool
	 */
	protected function isLoggedIn($auth, $credentials) {
		return !empty($this->logins[$auth->name()])
			&& $this->logins[$auth->name()] === $this->arrayCacheKey($credentials);
	}

	/**
	 * Check if there are any cookies for given network
	 *
	 * @param App\Ever\Http\Auth\AuthInterface $auth
	 * @return bool
	 */
	protected function hasCookies($auth) {
		return !empty($this->logins[$auth->name()]);
	}

	/**
 	 * Mark given provider as authorized
	 * @param AuthInterface $auth
	 * @param array $credentials
	 */
	protected function markAsLoggedIn($auth, $credentials) {
		$this->logins[$auth->name()] = $this->arrayCacheKey($credentials);
	}

	/**
	 * Cache decorator and extend timeout
	 * @param Request $request
	 */
	protected function doRequest($request) {
		if ($cache = $this->getRequestCache($request)) {
			return $cache;
		}

		$attempt = 0;
		while ($attempt < static::ATTEMPTS) {
			try {
				$result = parent::doRequest($request);

				if ($this->useCache) {
					$this->setRequestCache($request, $result);
				}

				return $result;
			} catch (\Exception $e) {
				$attempt++;
			}
		}

		throw new \Exception('20 request attempts failed.');
	}

	protected function getCache($key) {
		return empty($this->cache[$key])
			? false
			: $this->cache[$key];
	}

	protected function hasCache($key) {
		return isset($this->cache[$key]);
	}

	protected function setCache($key, $value) {
		$this->cache[$key] = $value;
	}

	protected function getRequestCache($request) {
		// Cache only GET requests
		if ('GET' !== $request->getMethod()) return false;

		$key = $this->requestCacheKey($request);
		return $this->getCache($key);
	}

	protected function setRequestCache($request, $value) {
		// Cache only GET requests
		if ('GET' !== $request->getMethod()) return false;

		$key = $this->requestCacheKey($request);
		$this->setCache($key, $value);
	}

	protected function requestCacheKey($request) {
		return md5($request->getUri());
	}

	protected function arrayCacheKey($array) {
		return md5(serialize($array));
	}
}
