<?php namespace exface\Core\CommonLogic;

use exface\Core\Interfaces\UiPageInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\TemplateInterface;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Interfaces\UiManagerInterface;
use exface\Core\Exceptions\Widgets\WidgetIdConflictError;
use exface\Core\Interfaces\Widgets\iHaveChildren;

class UiPage implements UiPageInterface {
	private $widgets = array();
	private $id = null;
	private $template = null;
	private $ui = null;
	private $widget_root = null;
	
	const WIDGET_ID_SEPARATOR = '_';
	
	/**
	 * @deprecated use UiPageFactory::create() instead!
	 * @param TemplateInterface $template
	 */
	public function __construct(UiManagerInterface $ui){
		$this->ui = $ui;
	}
	
	/**
	 * 
	 * @param WidgetInterface $widget
	 * @throws WidgetIdConflictError
	 * @return \exface\Core\CommonLogic\UiPage
	 */
	public function add_widget(WidgetInterface $widget){
		$widget->set_id_autogenerated($this->generate_id($widget));
		if ($widget->get_id_specified() && $widget->get_id_specified() != $this->sanitize_id($widget->get_id_specified())){
			throw new WidgetIdConflictError($widget, 'Explicitly specified id "' . $widget->get_id_specified() . '" for widget "' . $widget->get_widget_type() . '" not unique on page "' . $this->get_id() . '": please specify a unique id for the widget in the UXON description of the page!');
			return $this;
		}
		
		// Remember the first widget added automatically as the root widget of the page
		if (count($this->widgets) === 0){
			$this->widget_root = $widget;
		}
		
		$this->widgets[$widget->get_id()] = $widget;
		return $this;
	}
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\Interfaces\UiPageInterface::remove_widget()
	 */
	public function remove_widget(WidgetInterface $widget, $remove_children_too = true){
		return $this->remove_widget_by_id($widget->get_id());
	}
	
	/**
	 * 
	 * @return \exface\Core\Interfaces\WidgetInterface
	 */
	public function get_widget_root(){
		return $this->widget_root;
	}
	
	/**
	 * Generates an unique id for the given widget. If the widget has an id already, this is merely sanitized.
	 * @param WidgetInterface $widget
	 * @return string
	 */
	protected function generate_id(WidgetInterface $widget){
		if (!$id = $widget->get_id()){
			if ($widget->get_parent()){
				$id = $widget->get_parent()->get_id() . self::WIDGET_ID_SEPARATOR;
			}
			$id .= $widget->get_widget_type();
		}
		return $this->sanitize_id($id);
	}
	
	/**
	 * Makes sure, the given widget id is unique in this page. If not, the id gets a numeric index, which makes it unique.
	 * Thus, the returned value is guaranteed to be unique!
	 * @param string $string
	 * @return string
	 */
	protected function sanitize_id($string){
		if ($this->widgets[$string]){
			$index = substr($string, -2);
			if (is_numeric($index)){
				$index_new = str_pad(intval($index+1), 2, 0, STR_PAD_LEFT);
				$string = substr($string, 0, -2) . $index_new;
			} else {
				$string .= '02';
			}
			
			return $this->sanitize_id($string);
		}
		return $string;
	}
	
	/**
	 * 
	 * @param string $widget_id
	 * @return WidgetInterface
	 */
	public function get_widget($widget_id){
		// First check to see, if the widget id is already in the widget list. If so, return the corresponding widget.
		// Otherwise look throgh the entire tree to make sure, even subwidgets with late binding can be found (= that is
		// those, that are created if a certain property of another widget is accessed.
		if (!$widget = $this->widgets[$widget_id]){
			if ($this->get_widget_root()){
				$widget = $this->get_widget_root()->find_child_recursive($widget_id);
			}
		}
		return $widget;
	}
	
	/**
	 * 
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}
	
	/**
	 * 
	 * @param string $value
	 * @return \exface\Core\CommonLogic\UiPage
	 */
	public function set_id($value) {
		$this->id = $value;
		return $this;
	} 
	
	/**
	 * 
	 * @return \exface\Core\Interfaces\TemplateInterface
	 */
	public function get_template() {
		if(is_null($this->template)){
			// FIXME need a method to get the template from the CMS page here somehow. It should probably become a method of the CMS-connector
			// The mapping between CMS-templates and ExFace-templates needs to move to a config variable of the CMS-connector app!
		}
		return $this->template;
	}
	
	/**
	 * 
	 * @param TemplateInterface $template
	 * @return \exface\Core\CommonLogic\UiPage
	 */
	protected function set_template(TemplateInterface $template){
		$this->template = $template;
		return $this;
	}
	
	/**
	 * 
	 * @param string $widget_type
	 * @param WidgetInterface $parent_widget
	 * @param string $widget_id
	 * @return unknown
	 */
	public function create_widget($widget_type, WidgetInterface $parent_widget = null, UxonObject $uxon = null){
		if ($uxon){
			$uxon->set_property('widget_type', $widget_type);
			$widget = WidgetFactory::create_from_uxon($this, $uxon, $parent_widget);
		} else {
			$widget = WidgetFactory::create($this, $widget_type, $parent_widget);
		}
		return $widget;
	}
	
	/**
	 * 
	 * @param string $widget_id
	 * @return \exface\Core\CommonLogic\UiPage
	 */
	public function remove_widget_by_id($widget_id, $remove_children_too = true){
		if ($remove_children_too){
			$widget = $this->get_widget($widget_id);
			if ($widget instanceof iHaveChildren){
				foreach ($widget->get_children() as $child){
					$this->remove_widget($child, true);
				}
			}
		}
		unset($this->widgets[$widget_id]);
		return $this;
	}
	
	/**
	 * @return UiManagerInterface
	 */
	public function get_ui(){
		return $this->ui;
	}
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\Interfaces\ExfaceClassInterface::get_workbench()
	 */
	public function get_workbench(){
		return $this->get_ui()->get_workbench();
	}
  
}

?>
