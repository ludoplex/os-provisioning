<?php

namespace Modules\ProvBase\Database\Seeders;

// Composer: "fzaninotto/faker": "v1.3.0"
use Faker\Factory as Faker;
use Modules\ProvBase\Entities\Contract;
use Modules\ProvBase\Entities\Qos;
use Faker\Provider\de_DE\Payment;			// SEPA: should not be required in Laravel 5 (L5), see ***

class ContractTableSeeder extends \BaseSeeder {

	public function run()
	{

		foreach(range(1, $this->max_seed) as $index)
		{
			Contract::create(static::get_fake_data());
		}
	}

	/**
	 * Returns an array with faked contract data; used e.g. in seeding and testing
	 *
	 * @author Patrick Reichel
	 */
	public static function get_fake_data() {

		$faker = Faker::create();
		$count = Contract::get(['id'])->count();
		$start_contract = $faker->dateTimeBetween('-10 years', '+1 year');
		$salutations = ['Herr', 'Frau', 'Firma', 'Behörde'];

		$ret = [
			'number' => 'contr_'.($count + 1),
			'number2' => 'legacy_contr_'.($count + 13157),
			'number3' => 'Cu/2015/Q4/'.($count),
			'number4' => 'legacy_cust_'.($count + 180558),
			'company' => (rand(0,10) > 7 ? $faker->company: ''),
			'salutation' => $salutations[array_rand($salutations, 1)],
			'academic_degree' => '',
			'firstname' => $faker->firstName,
			'lastname' => $faker->lastName,
			'street' => $faker->streetName,
			'house_number' => rand(1, 128),
			'city' => $faker->city,
			'zip' => $faker->postcode,
			'country_id' => 0,
			'x' => 13 + $faker->longitude() / 10,
			'y' => 50 + $faker->latitude() / 10,
			'phone' => $faker->phoneNumber,
			'fax' => (rand(0,10) > 7 ? $faker->phoneNumber : ''),
			'email' => $faker->email,
			'birthday' => $faker->dateTimeBetween('-100 years', '-18 years'),
			'network_access' => $faker->boolean(85),
			'contract_start' => $start_contract,
			'contract_end' => (rand(0,10) > 8 ? $faker->dateTimeBetween($start_contract, '+1 year') : 0),
			'qos_id' => Qos::all()->random(1)->id,
			'next_qos_id' => (rand(0,10) > 8 ? Qos::all()->random(1)->id : 0),
			'voip_id' => rand(0, 2),								// TODO: use Envia interface
			'next_voip_id' => (rand(0,10) > 8 ? rand(0, 2) : 0),
			'sepa_iban' => Payment::bankAccountNumber(),			// L5: replace with iban ***
			'sepa_bic' => $faker->swiftBicNumber,
			'sepa_holder' => (rand(0,10) > 8 ? $faker->name : ''),
			'sepa_institute' => $faker->colorName.' Bank',			// L5: use ->bank
			'create_invoice' => true,  //$faker->boolean(10),				// true means invoice will pe send via post office each month
			'login' => $faker->userName,							// for feature use. Now it should same as id
			'password' => \Acme\php\Password::generate_password(),
			'description' => $faker->sentence,
		];

		return $ret;
	}

}
