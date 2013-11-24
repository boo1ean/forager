<?php namespace Forager\Auth;

use Forager\Client;

interface AuthInterface
{
	/**
	 * Logs in given http client
	 *
	 * @param Forager\Client $client http client
	 * @param array $credentials username, password and stuff
	 * @return Forager\Client logged in client
	 */
	public function login(Client $client, $credentials);

	/**
	 * Logs given http client out
	 *
	 * @param App\Ever\Http\Client $client http client
	 */
	public function logout(Client $client);

	/**
	 * Return auth name
	 * @return string
	 */
	public function name();
}
