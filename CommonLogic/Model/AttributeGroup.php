<?php namespace exface\Core\CommonLogic\Model;

use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\AttributeGroupFactory;

/**
 * An attribute group contains groups any number of attributes of a single object (including inherited attributes!). 
 * An attribute group can be populated either be manually or using predifined selectors. Technically an attribute list with 
 * an alias and some preconfigured groups (and respective aliases) to quickly select certain types of attributes of an
 * object.
 * 
 * A manually create attribute group can even contain attributes of related objects. The only limitation is, that all
 * attributes must be selectable from the parent object of the group: thus, they must be related somehow.
 * 
 * IDEA use a Condition as a selector to populate the group
 * @author Andrej Kabachnik
 * 
 */
class AttributeGroup extends AttributeList {
	private $alias = NULL;
	
	const ALL = '~ALL';
	const VISIBLE = '~VISIBLE';
	const REQUIRED = '~REQUIRED';
	const EDITABLE = '~EDITABLE';
	
	public function get_alias() {
		return $this->alias;
	}
	
	public function set_alias($value) {
		$this->alias = $value;
		return $this;
	}  
	
	/**
	 * This is an alias for AttributeList->get_all()
	 * @return Attribute[]
	 */
	public function get_attributes(){
		return parent::get_all();
	}
	
	/**
	 * Returns a new attribute group, that contains all attributes of the object, that were not present in the original group
	 * E.g. group(~VISIBLE)->get_inverted_attribute_group() will hold all hidden attributes.
	 * @return \exface\Core\CommonLogic\Model\AttributeGroup
	 */
	public function get_inverted_attribute_group(){
		$object = $this->get_meta_object();
		$group = AttributeGroupFactory::create_for_object($object);
		foreach ($this->get_meta_object()->get_attributes() as $attr){
			if (!in_array($attr, $this->get_attributes())){
				$group->add_attribute($attr);
			}
		}
		return $group;
	}
}