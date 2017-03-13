<?php

namespace Modules\HfcSnmp\Http\Controllers;

use Modules\HfcReq\Entities\NetElement;
use Modules\HfcReq\Entities\NetElementType;
use Modules\HfcSnmp\Entities\SnmpValue;
use Modules\HfcSnmp\Entities\OID;
use Modules\HfcSnmp\Entities\Parameter;
use \App\Http\Controllers\BaseViewController;
use Modules\HfcReq\Http\Controllers\NetElementController;


use Log;

class SnmpController extends \BaseController{

	private $timeout = 300000;
	private $retry = 1;

	/**
	 * @var  Object 	NetElement
	 */
	private $device;

	/**
	 * @var  Array  of OID-Objects that threw an exception during SNMP-Set
	 */
	private $set_errors = [];


	/**
	 * @var  Bool 	If Set we only want to show the 3rd dimension parameters of this index for the controlling view
	 */
	private $index = 0;


	/**
	 * Init SnmpController with a certain Device Model and
	 * a MIB Array
	 *
	 * @param device the Device Model
	 * @param mibs the MIB array
	 *
	 * @author Torsten Schmidt
	 */
	public function init ($device = null)
	{
		$this->device = $device;

		$this->snmp_def_mode();
	}

	/**
	 * Returns the Controlling View for a NetElement (Device)
	 *
	 * Note: This function is used again for the 3rd Dimension of a Snmp Table (of which the Index link references to)
	 *
	 * @param 	id  		The NetElement id
	 * @param 	param_id 	ID of the Parameter for 3rd Dimension View
	 * @param 	index 		The Index we want to see 3rd Dim for
	 * @author 	Torsten Schmidt, Nino Ryschawy
	 */
	public function controlling_edit($id, $param_id = 0, $index = 0)
	{
		// Init NetElement Model & SnmpController
		$netelem = NetElement::findOrFail($id);
		$snmp 	 = new SnmpController;
		$snmp->init ($netelem);
		$snmp->index = $index ? [$index] : 0;

// d(\HTML::linkRoute('NetElement.param_entry_edit', 'Controlling', [31, 0, 0]),
// 	route('NetElement.param_entry_edit', [31, 238, 6851]));

		$start = microtime(true);
		// Get Html Form Fields for generic View - this includes the snmpwalk & updating snmpvalues
		$params 	 = $index ? Parameter::where('parent_id', '=', $param_id)->where('third_dimension', '=', 1)->orderBy('id')->get()->all() : $snmp->device->netelementtype->parameters;
		$form_fields = $snmp->prep_form_fields($params);

		// TODO: use multi update function
		// $this->_multi_update_values();
		$form_fields = static::_make_html_from_form_fields($form_fields);

// d(round(microtime(true) - $start, 2), $form_fields);

		// Init View
		$view_header = 'SNMP Settings: '.$netelem->name;
		$view_var 	 = $netelem;
		$route_name  = \NamespaceController::get_route_name();
		$headline 	 = BaseViewController::compute_headline(\NamespaceController::get_route_name(), $view_header, $view_var).' controlling';

		$panel_right = new NetElementController;
		$panel_right = $panel_right->prepare_tabs($view_var);

		$view_path   = 'hfcsnmp::NetElement.controlling';
		$form_path   = 'Generic.form';
		$form_update = 'NetElement.controlling_update';

		$reload 	 = $snmp->device->netelementtype->page_reload_time ? : 0;

		return \View::make($view_path, $this->compact_prep_view(compact('view_var', 'view_header', 'form_path', 'panel_right', 'form_fields', 'form_update', 'route_name', 'headline', 'reload')));
	}


	/**
	 * Controlling Update Function
	 *
	 * @param id the NetElement id
	 * @author Torsten Schmidt
	 */
	public function controlling_update($id)
	{
		// Init SnmpController
		$netelem = NetElement::findOrFail($id);
		$snmp = new SnmpController;
		$snmp->init ($netelem);

		// TODO: validation
		// $validator = \Validator::make($data = $this->prepare_input(\Input::all()), $netelem::rules($id));
		// if ($validator->fails())
		// 	return Redirect::back()->withErrors($validator)->withInput();


		// Transfer Settings via SNMP to Device
		$data = \Request::all();
		$snmp->snmp_set_all($data);


		// Build Error Message in case OIDs could not be set
		if ($snmp->set_errors)
		{
			$msg = 'The following Parameters could not be Set: ';
			$msg_color = 'red';

			foreach ($snmp->set_errors as $k => $oid)
			{
				$msg .= $k ? ', ' : '';
				$msg .= $oid->name_gui ? : $oid->name;
			}

			$msg .= '!';
		}
		else
		{
			$msg = 'Updated!';
			$msg_color = 'blue';
		}

		return \Redirect::route('NetElement.controlling_edit', $id)->with('message', $msg)->with('message_color', $msg_color);
	}


	/**
	 * Prepare Formular Fields for Controlling View of NetElement
	 * This includes getting all SNMP Values from Device
	 * 
	 * @return 	Array (Multidimensional)	Data for Generic Form View in Form [frame1 => [field1, field2, ...], frame2 => [...], ...]
	 *
	 * @author Torsten Schmidt, Nino Ryschawy
	 */
	public function prep_form_fields($params)
	{
		$form_fields = [];
		$table_index = 0;

		if (!$this->device->ip)
			return [];

		// TODO: if device not reachable take already saved SnmpValues from Database but show a hint
		// if (!$results) ...

		$start = microtime(true);

		foreach ($params as $param)
		{
			$oid = $param->oid;
			$indices = [];

			if ($param->indices && $param->indices->indices)
				$indices = \Acme\php\ArrayHelper::str_to_array($param->indices->indices);
			if ($this->index)
				$indices = $this->index;


			// Table Param
			if ($oid->oid_table)
			{
				$table_index++;

				if ($param->third_dimension_params())
				{
					// Add Info for Generation of the 3rd Dimension Links of a Table (if param has 3rd dim params)
					$form_fields['table'][$table_index]['options']['3rd_dim_link']['netelement_id'] = $this->device->id;
					$form_fields['table'][$table_index]['options']['3rd_dim_link']['param_id'] = $param->id;
				}

				$results = $this->snmp_table($param, $indices);

				foreach ($results as $res_oid => $values)
				{
					$suboid = OID::where('oid', '=', $res_oid)->get()->first();

					if (!$suboid)
					{
						// this should never occur
						Log::error('Missing OID Entry from '.$oid->name.' for: '.$res_oid, [$this->device->netelementtype->name]);
						continue;
					}

					foreach ($values as $index => $value)
					{
						/* Save SnmpValue
						 	NOTE: This takes way too much time
							TODO: only add valueset to global variable for later multiupdate - for that we have to change indexing 
								  of field names because we wont get them as return value anymore
						*/
						$ret = $this->_snmp_value_set($suboid, $value, $index);

						// reorder by index for easier graphical display
						$form_fields['table'][$table_index][$index][$res_oid] = $this->_get_formfield_array($suboid, $ret, $value, true);
					}
				}

				continue;
			}

			// Non Table Param
			$results = $this->snmp_walk($oid, $indices);

			foreach ($results as $oid->res_oid => $value)
			{
				// Save SnmpValue
				$ret = $this->_snmp_value_set($oid, $value);

				$field = $this->_get_formfield_array($oid, $ret, $value);

				// arrange in order inside form fields array structure - see Module Documentation
				if (!$param->html_frame)
				{
					$form_fields['list'][] = $field;
				}

				else if (strlen((string) $param->html_frame) == 1)
				{
					$form_fields['frame']['linear'][$param->html_frame][] = $field;
				}

				else
				{
					$frame = (string) $param->html_frame;
					$row = $frame[0];
					$col = $frame[1];

					$form_fields['frame']['tabular'][$row][$col][] = $field;
				}

			}
		}

// if ($suboid->oid == '.1.3.6.1.2.1.10.127.1.1.1.1.2')
// d(round(microtime(true) - $start, 2). 'sec', $form_fields);

		return $form_fields;
	}


	/**
	 * Generate Form Field array as preparation for creating the html form fields from it
	 *
	 * @param ret 	Array 	Return Value from _snmp_value_set [0 => oid_id, 1 => 'oid_index']
	 */
	private function _get_formfield_array($oid, $ret, $value, $table = false)
	{
		$id = $ret[0];
		$options = null;

		if ($table)
		{
			$index = '';
			// TODO: Move to get_html_input ??
			$options['style'] = 'simple';
			if (in_array($oid->type, ['i', 'u', 't']))
				$options['style'] .= ";width: 85px";
		}
		else
		{
			$index   = $ret[1] == '.0' ? '' : $ret[1];
		}

		if ($oid->access == 'read-only')
			$options[] =  'readonly';

		$field = array(
			'form_type' 	=> $oid->html_type,
			'name' 			=> 'field_'.$id,	 		// = SnmpValue->id - TODO: Check if string 'field_' is necessary in front
			'description' 	=> $oid->name_gui ? $oid->name_gui.$index : $oid->name.$index,
			'field_value' 	=> $oid->unit_divisor && is_numeric($value) ? $value / $oid->unit_divisor : $value,
			'options' 		=> $options,
			// 'help' 			=> $oid->description,
			);

		if ($oid->html_type == 'select')
			$field['value'] = $oid->get_select_values();

// if ($oid->name_gui == 'Lower Pilot Modulation')
// d($value, $field);

		return $field;
	}


	/**
	 * This implements the whole form fields data structure - see Confluence MVC Documentation
	 */
	private static function _make_html_from_form_fields($form_fields)
	{
		$row = 1;
		$col = 1;
		$form_fields_html = ['list' => [], 'table' => [], 'frame' => ['linear' => [], 'tabular' => []]];

		// Table
		if (isset($form_fields['table']))
		{
			foreach ($form_fields['table'] as $key => $table)
				$form_fields_html['table'][$key] = static::_make_html_snmp_table($table);
		}

		// List
		if (isset($form_fields['list']))
		{
			foreach ($form_fields['list'] as $key => $field)
				$form_fields_html['list'][$key] = BaseViewController::add_html_string(array($field))[0]['html'];
		}

		// Frame
		if (isset($form_fields['frame']['linear']))
		{
			foreach ($form_fields['frame']['linear'] as $list)
			{
				foreach ($list as $key => $field)
				{
					$field = BaseViewController::add_html_string(array($field))[0]['html'];

					$form_fields_html['frame']['linear'][$row][$col][] = $field;
				}

				$col++;
				if ($col % 4 == 0)
				{
					$row++;
					$col = 1;
				}
			}
		}

		if (isset($form_fields['frame']['tabular']))
		{
			foreach ($form_fields['frame']['tabular'] as $row)
			{
				foreach ($row as $list)
				{
					foreach ($list as $field)
					{
						$field = BaseViewController::add_html_string(array($field))['html'];

						$form_fields_html['frame']['tabular'][$row][$col][] = $field;
					}
				}
			}
		}

		return $form_fields_html;

	}


	/**
	 * Create HTML Table out of the list of prepared form fields for a Table
	 *
	 * @param 	Array 	List of form fields of a Table
	 * @return 	String 	HTML Code of the Table
	 *
	 * TODO: standardise and move to BaseViewController!
	 * @author 	Nino Ryschawy
	 */
	private static function _make_html_snmp_table($form_fields)
	{
		$s = '';
		$head = true;
		$third_dim = false;
		if (isset($form_fields['options']['3rd_dim_link']))
		{
			$third_dim = true;
			$options = $form_fields['options']['3rd_dim_link'];
			unset($form_fields['options']);
		}

		foreach ($form_fields as $index => $oids)
		{
			if ($head)
			{
				// table head
				$s .= '<table class="table table-condensed">';
				$s .= '<thead><tr>';
				$s .= '<th>Index</th>';
				
				foreach ($oids as $oid => $field)
					$s .= '<th style="padding: 2px">'.$field['description'].'</th>';

				$s .= '<tr></thead><tbody>';

				$head = false;
				reset($oids);
			}

			// table body
			// TODO: make index column a href link to controlling view for 3rd dimension
			// $index = $third_dim ? '<a href="'.route('NetElement.param_entry_edit', [$options['netelement_id'], $options['param_id'], $index]).'">'.$index : $index;
			$index = $third_dim ? '<a href="'.route('NetElement.controlling_edit', [$options['netelement_id'], $options['param_id'], $index]).'">'.$index : $index;
			$s .= '<tr><td>'.$index.'</td>';

			foreach ($oids as $oid => $field)
				$s .= '<td style="padding: 2px">'.BaseViewController::get_html_input($field).'</td>';

			$s .= '</tr>';
		}

		// end table
		$s .= '</tbody></table>';

		return $s;
	}

	/**
	 * The SNMP Walk Function
	 *
	 * make a snmpwalk over the entire $oid->oid
	 * and create/update related SnmpValue Objects
	 *
	 * @param 	oid the OID Object
	 * @return 	array of snmpwalk over oid in format [SnmpValue object id, snmp value]
	 *
	 * @author Torsten Schmidt, Nino Ryschawy
	 */
	public function snmp_walk ($oid, $indices = [])
	{
		$community = $this->_get_community();
		$start = microtime(true);

		if ($indices)
		{
			foreach ($indices as $index)
				$results[$oid->oid.'.'.$index] = snmp2_get($this->device->ip, $community, $oid->oid.'.'.$index, $this->timeout, $this->retry);
		}
		else
			$results = snmprealwalk($this->device->ip, $community, $oid->oid, $this->timeout, $this->retry);

		// exec('snmpwalk -v2c -On -CE '.escapeshellarg($oid->oid).'.'.$indices['max'].' -c'.escapeshellarg($this->_get_community()).' '.escapeshellarg($this->device->ip).' '.escapeshellarg($oid->oid), $results);

		// Log
		Log::info('snmpwalk '.$this->device->ip.' '.$oid->oid);

// d(round(microtime(true) - $start, 3), $results, $indices);

		return $results;
	}


	/**
	 * SNMP Walk over a Table OID Parameter
	 *
	 * @param 	param 	table Object ID
	 * @return 	Array	[index => [oid => value]]
	 *
	 * @author 	Nino Ryschawy
	 */
	public function snmp_table($param, $indices)
	{
		$oid = $param->oid;
		$start = microtime(true);
		$results = [];
		$param_selection = $param->children();

		// exact defined table via SubOIDs
		if ($param_selection)
		{
			foreach ($param_selection as $param)
			{
				if ($param->third_dimension && !$this->index)
					continue;

				/* TODO: check with first walk how many indices exist, if this is approximately 3 or 4 (check performance!) times larger than 
					the indices list then only get oid.index for each index
					Note: snmpwalk -CE ends on this OID - makes it much faster
					*/
				// exec('snmpwalk -v2c -CE 1.3.6.1.2.1.10.127.1.1.1.1.3.6725 -c'.$this->_get_community().' '.$this->device->ip.' '.$oid->oid, $results);
				$oid = $param->oid;
				$results += $this->snmp_walk($oid, $indices);
			}

		}
		// standard table OID (all suboids(columns) and elements (rows))
		else
		{
			Log::info('snmp2_real_walk (table) '.$this->device->ip.' '.$oid->oid);
			$results = snmp2_real_walk($this->device->ip, $this->_get_community(), $oid->mibfile->name.'::'.$oid->name);
			// $results = snmp2_real_walk($this->device->ip, $this->_get_community(), "DOCS-IF-MIB::docsIfUpstreamChannelTable");
			// exec('snmptable -v2c -Ci -c'.$this->_get_community().' '.$this->device->ip.' '.escapeshellarg($oid->mibfile->name.'::'.$oid->name), $results);
		}

		if (!$results)
			Log::error('No Results for SnmpWalk over OID: '.$oid->oid); // Possible Reasons: wrong defined indices, device does not support oid


		// order by suboid for faster snmpvalue saving (still very slow)
		foreach ($results as $oid_index => $value)
		{
			$index = strrchr($oid_index, '.');
			$oid_s = substr($oid_index, 0, strlen($oid_index) - strlen($index));
			$index = substr($index, 1);

			// Exclude unwished indices - this is a workaround for the unimproved snmpwalk over all indices 
			// we filter them temporarily here - TODO: Improve performance via better snmpwalk
			if (!$param_selection)
			{
				if ($indices && !in_array($index, $indices))
					continue;
			}

			$res[$oid_s][$index] = $value;
		}

// if ($param->parent_id == 238)
// d(round(microtime(true) - $start, 3), $oid, $res, $results, $param);

		return $res;
	}


	/**
	 * Create or Update SnmpValue Object which corresponds
	 * to $oid and $this->device with $value
	 *
	 * @param 	oid 	the OID Object
	 * @param 	value 	from snmpget command for this oid object
	 * @return 	the ID of the SnmpValue Model
	 *
	 * @author Torsten Schmidt, Nino Ryschawy
	 */
	private function _snmp_value_set ($oid, $value, $index = '')
	{
		// $obj = SnmpValue::updateOrCreate($data); 		// doesnt work as is

		$data = array(
			'netelement_id' => $this->device->id,
			'oid_id' 		=> $oid->id,
			'value' 		=> $value,
			'oid_index' 	=> $index ? : str_replace($oid->oid, '', $oid->res_oid),
			);

		$obj = SnmpValue::where('netelement_id', '=', $this->device->id)->where('oid_id', '=', $oid->id)->where('oid_index', '=', $data['oid_index'])->get()->first();
		// $obj = $snmpvalues->where('oid_id', $oid->id)->where('oid_index', (string) $data['oid_index'])->first();

		if ($obj)
		{
			// always update to get the latest timestamp ??
			// $data['updated_at'] = \Carbon\Carbon::now(\Config::get('app.timezone'));
			// Note: update method needs id to update correct element
			$obj->update($data);

			return [$obj->id, $data['oid_index']];
		}

		return [SnmpValue::create($data)->id, $data['oid_index']];
	}


	/**
	 * Create or Update SnmpValue Object which corresponds
	 * to $oid and $this->device with $value
	 *
	 * @param string encoded array like "3:qam64;4:qam256"
	 * @return encoded array of string like [3]=>"qam64", [4]=>"qam256"
	 * @author Torsten Schmidt
	 */
	private function string_to_array ($s)
	{
		$ret = array();
		foreach (explode (';', $s) as $line)
			$ret[explode(':', $line)[0]] = explode(':', $line)[1];
		return $ret;
	}

	/**
	 * Return the Community String for Read-Only or Read-Write Access
	 *
	 * @param 	access 	String 	'ro' or 'rw'
	 * @author 	Nino Ryschawy
	 */
	private function _get_community($access = 'ro')
	{
		return $this->device->{'community_'.$access} ? : \Modules\ProvBase\Entities\ProvBase::get([$access.'_community'])->first()->{$access.'_community'};
	}


	/**
	 * Perform a SNMP set of all SNMP Values for this Controller
	 *
	 * @param 	data 	the HTML data array in form: ['field_<SnmpValue ID>' => <value>]
	 * @return 	form_fields array for generic edit view function
	 *
	 * @author Torsten Schmidt
	 */
	public function snmp_set_all($data)
	{
		$eager_loading_model = new OID;
		$snmpvalues = SnmpValue::where('netelement_id', '=', $this->device->id)->with($eager_loading_model->table)->get();
		$pre_conf = true; 			// true - has to be done

		foreach ($data as $field => $value)
		{
			$arr = explode('_', $field);
			if ($arr[0] == 'field')
			{
				// explode data & write to Database
				$id  = $arr[1];
				$snmp_val = $snmpvalues->find($id);

				// In GUI the value was divided by divisor - multiplicate back now for value comparison
				if ($snmp_val->oid->unit_divisor)
					$value *= $snmp_val->oid->unit_divisor;

				// Set Value of Parameter in Database & Device only if it was changed in GUI
				if ($snmp_val->value != $value)
				{
					// Do preconfiguration if necessary
					if ($pre_conf)
					{
						$conf_val = $this->_configure();
						$pre_conf = false;
					}

					$snmp_val->value = $value;
					$snmp_val->save();

					// Set Value in Device via SNMP
					$this->snmp_set($snmp_val);
				}
			}
		}

		if (isset($conf_val))
			$this->_configure($conf_val);

		return true;
	}


	/**
	 * Set the corresponding Values to Configure the Device for a successful snmpset (e.g. needed by kathrein amplifiers)
	 * NOTE: If Value is specified the post configuration is done
	 *
	 * @param 	value   the value of the Parameter before the Configuration to reset
	 * @return 	value of Parameter before the configuration, null when resetting the Parameter to this value (specified in argument)
	 *
	 * @author 	Nino Ryschawy
	 */
	private function _configure($value = null)
	{
		$type = $this->device->netelementtype;

		if (!$type->pre_conf_oid_id || !$type->pre_conf_value)
		{
			\Log::debug('No SNMP Preconfiguration defined for this Device (NetElement)', [$this->device->name]);
			return null;
		}

		$oid = $type->oid;

		// PreConfiguration
		if (!$value)
		{
			$conf_val = snmpget($this->device->ip, $this->_get_community(), $oid->oid.'.0', $this->timeout, $this->retry);

			$ret = false;
			if ($conf_val != $type->pre_conf_value)
				$ret = snmpset($this->device->ip, $this->_get_community('rw'), $oid->oid.'.0', $oid->type, $type->pre_conf_value, $this->timeout, $this->retry);

			$ret ? \Log::debug('Preconfigured Device for snmpset', [$this->device->name]) : \Log::debug('Failed to Preconfigure Device for snmpset', [$this->device->name]);

			return $conf_val;
		}

		// PostConfiguration
		snmpset($this->device->ip, $this->_get_community('rw'), $oid->oid.'.0', $oid->type, $value, $this->timeout, $this->retry);

		// wait time in msec
		$sleep_time = $type->pre_conf_time_offset ? : 0;
		usleep($sleep_time);

		return null;
	}



	/**
	 * The SNMP Set Function
	 *
	 * snmpset the $snmpvalue object with $snmpvalue->value
	 *
	 * Note: performs snmpsetdiff
	 *
	 * @param snmpvalue the SnmpValue Object
	 * @return true if success, otherwise false
	 *
	 * @author Torsten Schmidt
	 */
	public function snmp_set ($snmpvalue)
	{
		$community  = $this->_get_community();
		$oid 		= $snmpvalue->oid;
		$index 		= $snmpvalue->oid_index ? : '.0';

		$ret = snmpget($this->device->ip, $community, $snmpvalue->oid->oid.$index, $this->timeout, $this->retry);

		if ($ret === FALSE)
			return FALSE;

		if ($ret == $snmpvalue->value)
			return TRUE;

		Log::info('snmp: set diff '.$this->device->ip.' '.$snmpvalue->value.' '.$oid->type.' '.$snmpvalue->value.' '.$ret);

		// catch all OIDs that could not be set to print later in error message
		try {
			$val = snmpset($this->device->ip, $this->_get_community('rw'), $oid->oid.$index, $oid->type, $snmpvalue->value, $this->timeout, $this->retry);
		} catch (\ErrorException $e) {
			$this->set_errors[] = $oid;
			return null;
		}

		return $val;
	}


	/**
	 * Set PHP SNMP Default Values
	 * Note: Must be only called once per Object Init
	 *
	 * @author Torsten Schmidt
	 */
	private function snmp_def_mode()
	{
		snmp_set_quick_print(TRUE);
		snmp_set_oid_numeric_print(TRUE);
		snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
		snmp_set_oid_output_format (SNMP_OID_OUTPUT_NUMERIC);
	}


	// private function _multi_update_values()
	// {

	// 	/* NOTES: SQL Changes for multiple primary keys:
	// 		ALTER TABLE snmpvalue DROP PRIMARY KEY, ADD PRIMARY KEY(netelement_id, oid_id, oid_index);
	// 	 */

	// 	$data = array(
	// 		['netelement_id' => 28,
	// 		'oid_id' 		=> 760,
	// 		'value' 		=> 12,
	// 		'oid_index' 	=> '.0'],
	// 		['netelement_id' => 28,
	// 		'oid_id' 		=> 757,
	// 		'value' 		=> -100,
	// 		'oid_index' 	=> '.0'],
	// 		);
	
	// 	\DB::raw('insert into snmpvalue (netelement_id, oid_id, oid_index, value) VALUES (28, 760, 11, \'.0\'), (28, 757, -10, \'.0\') on duplicate key update value=VALUES(value)');
	// }

}