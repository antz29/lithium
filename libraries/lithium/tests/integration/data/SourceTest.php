<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2010, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace lithium\tests\integration\data;

use \Exception;
use \ArrayAccess;
use \lithium\data\Connections;

class Company extends \lithium\data\Model {

	public $hasMany = array('Employees');

	protected $_meta = array('connection' => 'test', 'locked' => false);
}

class Employee extends \lithium\data\Model {

	public $belongsTo = array('Company');

	protected $_meta = array('connection' => 'test', 'locked' => false);
}

class SourceTest extends \lithium\test\Unit {

	protected $_connection = null;

	public $companyData = array(
		array('name' => 'StuffMart', 'active' => true),
		array('name' => 'Ma \'n Pa\'s Data Warehousing & Bait Shop', 'active' => false)
	);

	/**
	 * @todo Make less dumb.
	 *
	 */
	public function setUp() {
		Company::config();
		Employee::config();
		$this->_connection = Connections::get('test');

		if (strpos(get_class($this->_connection), 'CouchDb')) {
			$this->_loadViews();
		}

		try {
			foreach (Company::all() as $company) {
				$company->delete();
			}
		} catch (Exception $e) {}
	}

	protected function _loadViews() {
		Company::create()->save();
	}

	/**
	 * @todo Make less dumb.
	 *
	 */
	public function tearDown() {
		try {
			foreach (Company::all() as $company) {
				$company->delete();
			}
		} catch (Exception $e) {}

	}

	/**
	 * Skip the test if no test database connection available.
	 *
	 * @return void
	 */
	public function skip() {
		$isAvailable = (
			Connections::get('test', array('config' => true)) &&
			Connections::get('test')->isConnected(array('autoConnect' => true))
		);
		$this->skipIf(!$isAvailable, "No test connection available");
	}

	/**
	 * Tests that a single record with a manually specified primary key can be created, persisted
	 * to an arbitrary data store, re-read and updated.
	 *
	 * @return void
	 */
	public function testSingleReadWriteWithKey() {
		$key = Company::meta('key');
		$new = Company::create(array($key => 12345, 'name' => 'Acme, Inc.'));

		$result = $new->data();
		$expected = array($key => 12345, 'name' => 'Acme, Inc.');
		$this->assertEqual($expected[$key], $result[$key]);
		$this->assertEqual($expected['name'], $result['name']);

		$this->assertFalse($new->exists());
		$this->assertTrue($new->save());
		$this->assertTrue($new->exists());

		$existing = Company::find(12345);
		$result = $existing->data();
		$this->assertEqual($expected[$key], $result[$key]);
		$this->assertEqual($expected['name'], $result['name']);
		$this->assertTrue($existing->exists());

		$existing->name = 'Big Brother and the Holding Company';
		$result = $existing->save();
		$this->assertTrue($result);

		$existing = Company::find(12345);
		$result = $existing->data();
		$expected['name'] = 'Big Brother and the Holding Company';
		$this->assertEqual($expected[$key], $result[$key]);
		$this->assertEqual($expected['name'], $result['name']);

		$this->assertTrue($existing->delete());
	}

	public function testRewind() {
		$key = Company::meta('key');
		$new = Company::create(array($key => 12345, 'name' => 'Acme, Inc.'));

		$result = $new->data();
		$this->assertTrue($result !== null);
		$this->assertTrue($new->save());
		$this->assertTrue($new->exists());

		$result = Company::all(12345);
		$this->assertTrue($result !== null);

		$result = $result->rewind();
		$this->assertTrue($result !== null);
		$this->assertTrue(!is_string($result));
	}

	public function testFindFirstWithFieldsOption() {
		$key = Company::meta('key');
		$new = Company::create(array($key => 1111, 'name' => 'Test find first with fields.'));
		$result = $new->data();

		$expected = array($key => 1111, 'name' => 'Test find first with fields.');
		$this->assertEqual($expected['name'], $result['name']);
		$this->assertEqual($expected[$key], $result[$key]);
		$this->assertFalse($new->exists());
		$this->assertTrue($new->save());
		$this->assertTrue($new->exists());

		$result = Company::find('first', array('fields' => array('name')));
		$this->assertFalse(is_null($result));

		$this->skipIf(is_null($result), 'No result returned to test');
		$result = $result->data();
		$this->assertEqual($expected['name'], $result['name']);

		$this->assertTrue($new->delete());
	}

	public function testReadWriteMultiple() {
		$companies = array();
		$key = Company::meta('key');

		foreach ($this->companyData as $data) {
			$companies[] = Company::create($data);
			$this->assertTrue($companies[count($companies) - 1]->save());
			$this->assertTrue($companies[count($companies) - 1]->{$key});
		}

		$this->assertIdentical(2, Company::count());
		$this->assertIdentical(1, Company::count(array('active' => true)));
		$this->assertIdentical(1, Company::count(array('active' => false)));
		$this->assertIdentical(0, Company::count(array('active' => null)));
		$all = Company::all();

		$expected = count($this->companyData);
		$this->assertEqual($expected, $all->count());
		$this->assertEqual($expected, count($all));

		$id = (string) $all->first()->{$key};
		$this->assertTrue(strlen($id) > 0);
		$this->assertTrue($all->data());

		foreach ($companies as $company) {
			$this->assertTrue($company->delete());
		}
		$this->assertIdentical(0, Company::count());
	}

	public function testRecordOffset() {
		foreach ($this->companyData as $data) {
			Company::create($data)->save();
		}
		$all = Company::all();

		$result = $all->first(function($doc) { return $doc->name == 'StuffMart'; });
		$this->skipIf(!$result instanceof ArrayAccess, 'Data class does not implement ArrayAccess');

		$expected = 'StuffMart';
		$this->assertEqual($expected, $result['name']);

		$result = $result->data();
		$this->assertEqual($expected, $result['name']);

		$result = $all[1];
		$expected = 'Ma \'n Pa\'s Data Warehousing & Bait Shop';
		$this->assertEqual($expected, $result['name']);

		$result = $result->data();
		$this->assertEqual($expected, $result['name']);

		$this->assertNull($all[2]);
	}

	/**
	 * Tests that a record can be created, saved, and subsequently re-read using a key
	 * auto-generated by the data source. Uses short-hand `find()` syntax which does not support
	 * compound keys.
	 *
	 * @return void
	 */
	public function testGetRecordByGeneratedId() {
		$key = Company::meta('key');
		$company = Company::create(array('name' => 'Test Company'));
		$this->assertTrue($company->save());

		$id = $company->{$key};
		$companyCopy = Company::find($id)->data();
		$data = $company->data();

		foreach($data as $key => $value) {
			$this->assertTrue(isset($companyCopy[$key]));
			$this->assertEqual($data[$key], $companyCopy[$key]);
		}
	}

	/**
	 * Tests the default relationship information provided by the backend data source.
	 *
	 * @return void
	 */
	public function testDefaultRelationshipInfo() {
		$connection = $this->_connection;
		$message = "Relationships are not supported by this adapter.";
		$this->skipIf(!$connection::enabled('relationships'), $message);

		$this->assertEqual(array('Employees'), array_keys(Company::relations()));
		$this->assertEqual(array('Company'), array_keys(Employee::relations()));

		$this->assertEqual(array('Employees'), Company::relations('hasMany'));
		$this->assertEqual(array('Company'), Employee::relations('belongsTo'));

		$this->assertFalse(Company::relations('belongsTo'));
		$this->assertFalse(Company::relations('hasOne'));

		$this->assertFalse(Employee::relations('hasMany'));
		$this->assertFalse(Employee::relations('hasOne'));

		$result = Company::relations('Employees');
		$this->assertEqual('hasMany', $result->data('type'));
		$this->assertEqual(__NAMESPACE__ . '\Employee', $result->data('to'));
	}

	public function testRelationshipQuerying() {
		return;
		foreach ($this->companyData as $data) {
			Company::create($data)->save();
		}
		$related = $companies = Company::first()->employees->model();
		$this->assertEqual($related, __NAMESPACE__ . '\Employee');
	}
}

?>