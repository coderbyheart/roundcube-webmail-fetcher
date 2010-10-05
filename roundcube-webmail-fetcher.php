<?php

// Copyright (c) 2010 Markus Tacker
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in
// all copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
// THE SOFTWARE.

/**
 * This is a PHP script which connects to the web frontend of RoundCube,
 * fetches new unread mails and forwards them to another
 * email address (e.g. GMail) via sendmail.
 *
 * @author Markus Tacker <m@tacker.org> | http://coderbyheart.de/
 * @link http://github.com/tacker/roundcube-webmail-fetcher
 * @license MIT
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Your roundcube username
$username = 'someuser';
// Your roundcube password
$password = 'somepass';
// Your username and password for HTTP Basic Auth, if your admin put RoundCube behind one
$basicAuthUsername = null;
$basicAuthPassword = null;
$baseUrl = 'http://www.company.com/roundcube/';
$forwardTo = 'me@mydomain.com';

$curl = curl_init();
if ($basicAuthUsername !== null) curl_setopt($curl, CURLOPT_USERPWD, $basicAuthUsername . ':' . $basicAuthPassword);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$cookieFile = dirname(__FILE__) . '/cookies.txt';
curl_setopt($curl, CURLOPT_COOKIEJAR, $cookieFile);
// curl_setopt($curl, CURLOPT_VERBOSE, true);
curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($curl, CURLOPT_URL, $baseUrl);
curl_setopt($curl, CURLOPT_AUTOREFERER, true);

// Fetch login page for cookies
curl_exec($curl);
if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) throw new Exception('Initial request failed.');

// Login
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, array('_action' => 'login', '_user' => $username, '_pass' => $password));
$data = curl_exec($curl);
if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) throw new Exception('Login failed.');

// Request token is needed for all subsequent requests
preg_match('/request_token:\'([^\']+)\'/', $data, $match);
if (empty($match)) throw new Exception('Missing request token.');
$requestToken = $match[1];

// Fetch list of mails
curl_setopt($curl, CURLOPT_URL, $baseUrl . '?_task=mail&_action=list&_mbox=INBOX&_refresh=1&_remote=1&_=' . time() . '&_unlock=1');
curl_setopt($curl, CURLOPT_HTTPGET, true);
curl_setopt($curl, CURLOPT_HTTPHEADER, array('X-RoundCube-Request: ' . $requestToken));
if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) throw new Exception('Fetching mail list failed.');

// Get uids from list
$data = curl_exec($curl);
$data = trim(preg_replace('%^/\*\* ajax response \[[^\]]+\] \*\*/%', '', $data));
$data = preg_replace('%<[^>]+>%', '', $data);
$data = str_replace("'", '"', $data);
$data = str_replace('\"', "'", $data);
$data = str_replace('\n', ' ', $data);

preg_match_all('/this.add_message_row\(.+,[0-9]+,[0-9]+\);/U', $data, $listOfMails);
foreach($listOfMails[0] as $mailListEntry) {
	// Only fetch unread mails
	if (!strstr($mailListEntry, 'unread:1')) continue;
	preg_match('/^this\.add_message_row\(\'([0-9]+)\'/', $mailListEntry, $uidMatch);
	if (empty($uidMatch)) throw new Exception('Failed to get mail uid.');
	$uid = $uidMatch[1];

	// Fetch mail
	curl_setopt($curl, CURLOPT_URL, $baseUrl . '?_task=mail&_action=viewsource&_uid=' . $uid . '&_mbox=INBOX');
	curl_setopt($curl, CURLOPT_HTTPGET, true);
	$data = curl_exec($curl);

	// Send Mail
	$descriptorspec = array(
	   array('pipe', 'r'),
	   array('pipe', 'w'),
	   array('pipe', 'w')
	);
	$sendmail = proc_open('sendmail -r ' . $forwardTo . ' ' . $forwardTo, $descriptorspec, $pipes);
	if(!is_resource($sendmail)) throw new Exception('Could not open sendmail process.');
	fwrite($pipes[0], $data);
	fclose($pipes[0]);
	$info = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	$returnValue = proc_close($sendmail);
	if ($returnValue !== 0) throw new Exception('Sendmail failed with: ' . $info . ' / ' . $error);

	// Mark mail as read
	curl_setopt($curl, CURLOPT_URL, $baseUrl . '?_task=mail&_action=mark');
	curl_setopt($curl, CURLOPT_POST, true);
	curl_setopt($curl, CURLOPT_HTTPHEADER, array('X-RoundCube-Request: ' . $requestToken));
	curl_setopt($curl, CURLOPT_POSTFIELDS, array('_flag' => 'read', '_remote' => '1', '_uid' => $uid));
	$data = curl_exec($curl);
	if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) throw new Exception('Failed to mark mail as read.');
}

curl_close($curl);
unlink($cookieFile);