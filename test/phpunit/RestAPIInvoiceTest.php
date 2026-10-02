<?php
/* Copyright (C) 2026	OMP agent	<omp@example.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */

/**
 *      \file       test/phpunit/RestAPIInvoiceTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test of the /invoices REST API
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

require_once __DIR__."/AbstractRestAPITest.php";

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class RestAPIInvoiceTest extends AbstractRestAPITest
{
	/**
	 * testRestGetThirdparty
	 *
	 * Return the id of a thirdparty to invoice. The demo database ships with customers, so read one
	 * instead of creating it (the thirdparty API needs a customer code on this instance).
	 *
	 * @return int	ID of a thirdparty
	 */
	public function testRestGetThirdparty()
	{
		$url = $this->api_url.'/thirdparties?api_key='.$this->api_key.'&limit=1&sortfield=t.rowid&sortorder=ASC';
		$addheaders = array('Content-Type: application/json');

		$result = getURLContent($url, 'GET', '', 1, $addheaders, array('http', 'https'), 2);

		$this->assertEquals(0, $result['curl_error_no'], 'Listing the thirdparties should not have a curl error');
		$object = json_decode($result['content'], true);

		$this->assertIsArray($object, 'Parsing of json result must no be null');
		$this->assertNotEmpty($object, 'No thirdparty available to invoice');

		return (int) $object[0]['id'];
	}

	/**
	 * testRestCreateInvoiceReturnIdByDefault
	 *
	 * Without the returnfull flag, POST /invoices must keep returning the bare id, otherwise every
	 * existing integration doing "id = POST /invoices" silently breaks.
	 *
	 * @param	int		$socid	Id of the thirdparty created at previous test
	 * @return int				ID of the invoice created
	 *
	 * @depends testRestGetThirdparty
	 */
	public function testRestCreateInvoiceReturnIdByDefault($socid)
	{
		$url = $this->api_url.'/invoices?api_key='.$this->api_key;
		$addheaders = array('Content-Type: application/json');

		$body = json_encode(array("socid" => $socid));

		$result = getURLContent($url, 'POST', $body, 1, $addheaders, array('http', 'https'), 2);

		$this->assertEquals(0, $result['curl_error_no'], 'Creating the invoice should not have a curl error');
		$object = json_decode($result['content'], true);

		$this->assertIsInt($object, 'POST /invoices must return a bare id by default, got '.gettype($object));
		$this->assertGreaterThan(0, $object, 'ID return is no > 0');

		return (int) $object;
	}

	/**
	 * testRestCreateInvoiceReturnFull
	 *
	 * POST /invoices?returnfull=1 must return the complete invoice, so the caller does not need a
	 * second GET call to read back what it just created.
	 *
	 * @param	int		$socid	Id of the thirdparty created at previous test
	 * @return int				ID of the invoice created
	 *
	 * @depends testRestGetThirdparty
	 */
	public function testRestCreateInvoiceReturnFull($socid)
	{
		$url = $this->api_url.'/invoices?api_key='.$this->api_key.'&returnfull=1';
		$addheaders = array('Content-Type: application/json');

		$body = json_encode(array("socid" => $socid));

		$result = getURLContent($url, 'POST', $body, 1, $addheaders, array('http', 'https'), 2);

		$this->assertEquals(0, $result['curl_error_no'], 'Creating the invoice should not have a curl error');
		$object = json_decode($result['content'], true);

		$this->assertIsArray($object, 'POST /invoices?returnfull=1 must return an object, got '.gettype($object));
		$this->assertNotEquals(500, (empty($object['error']['code']) ? 0 : $object['error']['code']), 'Error'.(empty($object['error']['message']) ? '' : ' '.$object['error']['message']));

		// The returned payload must be the invoice, not the id
		$this->assertGreaterThan(0, (int) $object['id'], 'The created invoice id is missing');
		$this->assertNotEmpty($object['ref'], 'The created invoice ref is missing');

		// The flag is a transport parameter, it must not leak into the invoice properties
		$this->assertArrayNotHasKey('returnfull', $object, 'The returnfull flag must not be set as an invoice property');

		return (int) $object['id'];
	}

	/**
	 * testRestCreateInvoiceReturnFullInBody
	 *
	 * The flag must also work when sent into the JSON body, and must still be stripped from the
	 * properties forwarded to the invoice.
	 *
	 * @param	int		$socid	Id of the thirdparty created at previous test
	 * @return int				ID of the invoice created
	 *
	 * @depends testRestGetThirdparty
	 */
	public function testRestCreateInvoiceReturnFullInBody($socid)
	{
		$url = $this->api_url.'/invoices?api_key='.$this->api_key;
		$addheaders = array('Content-Type: application/json');

		$body = json_encode(array("socid" => $socid, "returnfull" => 1));

		$result = getURLContent($url, 'POST', $body, 1, $addheaders, array('http', 'https'), 2);

		$this->assertEquals(0, $result['curl_error_no'], 'Creating the invoice should not have a curl error');
		$object = json_decode($result['content'], true);

		$this->assertIsArray($object, 'POST /invoices with a body flag must return an object, got '.gettype($object));
		$this->assertGreaterThan(0, (int) $object['id'], 'The created invoice id is missing');
		$this->assertArrayNotHasKey('returnfull', $object, 'The returnfull flag must not be set as an invoice property');

		return (int) $object['id'];
	}

	/**
	 * testRestCreateInvoiceReturnFullMatchGet
	 *
	 * The point of the flag is to spare the caller the follow-up GET: the POST payload must carry
	 * the same content as GET /invoices/{id}.
	 *
	 * @param	int		$socid	Id of the thirdparty created at previous test
	 * @return void
	 *
	 * @depends testRestGetThirdparty
	 */
	public function testRestCreateInvoiceReturnFullMatchGet($socid)
	{
		$addheaders = array('Content-Type: application/json');

		// What a single POST /invoices?returnfull=1 returns
		$url = $this->api_url.'/invoices?api_key='.$this->api_key.'&returnfull=1';
		$body = json_encode(array("socid" => $socid));

		$result = getURLContent($url, 'POST', $body, 1, $addheaders, array('http', 'https'), 2);
		$this->assertEquals(0, $result['curl_error_no'], 'Creating the invoice should not have a curl error');
		$frompost = json_decode($result['content'], true);

		$this->assertIsArray($frompost, 'POST /invoices?returnfull=1 must return an object, got '.gettype($frompost));
		$this->assertGreaterThan(0, (int) $frompost['id'], 'The created invoice id is missing');

		// And the same object fetched with a GET
		$urlget = $this->api_url.'/invoices/'.$frompost['id'].'?api_key='.$this->api_key;
		$resultget = getURLContent($urlget, 'GET', '', 1, $addheaders, array('http', 'https'), 2);

		$this->assertEquals(0, $resultget['curl_error_no'], 'Getting the invoice should not have a curl error');
		$fromget = json_decode($resultget['content'], true);

		$this->assertIsArray($fromget, 'GET /invoices/{id} must return an object, got '.gettype($fromget));

		$this->assertEquals($fromget, $frompost, 'POST ?returnfull=1 must return the same content as GET /invoices/{id}');
	}
}
