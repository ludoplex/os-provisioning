<?php

namespace Modules\HfcSnmp\Entities;

class Parameter extends \BaseModel {

	public $table = 'parameter';

	public $guarded = ['name', 'table'];


	public static function boot()
	{
		parent::boot();

		Parameter::observe(new ParameterObserver);
	}


	// Add your validation rules here
	public static function rules($id = null)
	{
		return array(
			'html_frame' => 'numeric|min:1',
			'html_id' => 'numeric|min:0',
		);
	}

	// Name of View
	public static function view_headline()
	{
		return 'Parameter';
	}

	// link title in index view
	public function view_index_label()
	{
		$header = $this->oid->name_gui ? : $this->oid->name;
		$header .= ' - '.$this->oid->oid;

		return ['index' => [$this->oid->name, $this->oid->oid, $this->access],
				'index_header' => ['Name', 'OID', 'Access'],
				'header' => $header];
	}

	public function index_list()
	{
		$eager_loading_model = new OID;

		return $this->orderBy('id')->with($eager_loading_model->table)->get();
	}

	public function view_has_many()
	{
		$ret = [];

		if ($this->oid->oid_table)
		{
			$ret['Base']['SubOIDs']['view']['view'] = 'hfcreq::NetElementType.parameters';
			$ret['Base']['SubOIDs']['view']['vars']['list'] = $this->children() ? : [];
		}

		return $ret;
	}

	public function view_belongs_to ()
	{
		return $this->netelementtype;
	}


	/**
	 * Relations
	 */
	public function oid()
	{
		return $this->belongsTo('Modules\HfcSnmp\Entities\OID', 'oid_id');
	}

	public function netelementtype()
	{
		return $this->belongsTo('Modules\HfcReq\Entities\NetElementType', 'netelementtype_id');
	}


	public function children()
	{
		return Parameter::where('parent_id', '=', $this->id)->orderBy('3rd_dimension')->orderBy('id')->get()->all();
	}
	// public function view_belongs_to ()
	// {
	// 	return $this->mibfile;
	// }

}


class ParameterObserver {

	public function updating($parameter)
	{
		$parameter->indices = str_replace([' ', "\t"], '', $parameter->indices);
	}

}