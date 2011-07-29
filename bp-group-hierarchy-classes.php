<?php

define( 'BP_GROUPS_HIERARCHY_ANY_PARENT', -1 );

/**
 * Hierarchy-aware extension for Groups class
 */
class BP_Groups_Hierarchy extends BP_Groups_Group {

	var $vars = null;
	
	function bp_groups_hierarchy( $id, $parent_id = 0 ) {
		
		global $bp, $wpdb;

		if(!isset($bp->table_prefix)) {
			bp_core_setup_globals();
		}
		if(!isset($bp->groups)) {
			groups_setup_globals();
		}
		
		if(!is_numeric($id)) {
			$id = $this->check_slug( $id, $parent_id );
		}
		
		if ( $id ) {
			$this->id = $id;
			$this->populate();
		}
	}
	
	function populate() {
		global $wpdb, $bp;

		parent::populate();
		if ( $group = $wpdb->get_row( $wpdb->prepare( "SELECT g.* FROM {$bp->groups->table_name} g WHERE g.id = %d", $this->id ) ) ) {
			$this->parent_id = $group->parent_id;
			$this->true_slug = $this->slug;
			$this->path = $this->buildPath();
			$this->slug = $this->path;
		}
	}
	
	function buildPath() {
		
		$path = $this->true_slug;
		if($this->parent_id == 0) {
			return $path;
		}
		
		$parent = (object)array('parent_id'=>$this->parent_id);
		do {
			$parent = new BP_Groups_Hierarchy($parent->parent_id);
			$path = $parent->true_slug . '/' . $path;
		}
		while($parent->parent_id != 0);
		
		return $path;
	}
	
	function save() {
		
		global $bp, $wpdb;
		
		$this->slug = $this->true_slug;
		parent::save();
		
		if($this->id) {
			$sql = $wpdb->prepare(
				"UPDATE {$bp->groups->table_name} SET
					parent_id = %d
				WHERE
					id = %d
				",
				$this->parent_id,
				$this->id
			);
			
			if ( false === $wpdb->query($sql) ) {
				return false;
			}

			if ( !$this->id ) {
				$this->id = $wpdb->insert_id;
			}
			
			$this->path = $this->buildPath();
			$this->slug = $this->path;
			
			return true;
		}
		return false;
	}
	
	/**
	 * Does the passed group or current group have children?
	 * @param int GroupID optional ID of a group to check for children (will use current group object if omitted)
	 * @return array of child groups
	 */
	function has_children( $id = null) {
		global $bp, $wpdb;
		if(is_null($id)) {
			if(!isset($this->id) || $this->id == 0)	return false;
			$id = $this->id;
		}
		return $wpdb->get_col($wpdb->prepare("SELECT DISTINCT g.id FROM {$bp->groups->table_name} g WHERE g.parent_id=%d",$id));
	}
	
	/**
	 * Is the passed group a child of the current object, or the passed group id?
	 * @param int ChildGroupID ID of suspected child group
	 * @param int ParentID ID of parent group (optional - will refer to current group object if omitted)
	 */
	function is_child( $group_id, $parent_id = null ) {
		if(is_null($parent_id)) {
			if(!isset($this->id) || $this->id == 0)	return false;
			$parent_id = $this->id;
		}
		return $wpdb->get_var($wpdb->prepare("SELECT COUNT(g.id) FROM {$bp->groups->table_name} g WHERE g.parent_id=%d AND g.id = %d",$parent_id, $group_id));
	}
	
	/**
	 * Check whether slug is valid for a subgroup of passed parent group ID
	 * @param string Slug group slug to check
	 * @param int ParentID optional ID of parent group to search (ANY group if omitted)
	 */
	function check_slug( $slug, $parent_id = BP_GROUPS_HIERARCHY_ANY_PARENT ) {
		global $wpdb, $bp;

		if ( !$slug )
			return false;

		if($parent_id == BP_GROUPS_HIERARCHY_ANY_PARENT) {
			return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$bp->groups->table_name} WHERE slug = %s", $slug ) );
		}
		return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$bp->groups->table_name} WHERE slug = %s AND parent_id = %d", $slug, $parent_id ) );
	}
	
	function check_slug_stem( $path ) {
		
		global $bp, $wpdb;
		
		if(strpos( $path, '/' )) {
			$path = explode('/',$path);
			$path = $path[count($path)-1];
		}
		if(strlen($path) == 0)	return array();
		
		$slug = esc_sql(like_escape(stripslashes($path)));
		return $wpdb->get_col( "SELECT slug FROM {$bp->groups->table_name} WHERE slug LIKE '$slug%'" );
	}
	
	function group_exists( $path, $parent_id = 0 ) {
		
		if(strpos( $path, '/' )) {
			$path = explode('/',$path);
			$parent = $parent_id;
			foreach($path as $slug) {
				if($parent = self::check_slug( $slug, $parent )) {
					// Nothing to see here - keep descending into the path
				} else {
					return false;
				}
			}
			return $parent;
		} else {
			return self::check_slug( $path, $parent_id );
		}
	}
	
	function get_id_from_slug( $slug, $parent_id = 0 ) {
		return self::group_exists( $slug, $parent_id );
	}
	
	/**
	 * Get the full path for a group
	 */
	function get_path( $group_id ) {
		$group = new BP_Groups_Hierarchy( $group_id );
		if($group) {
			return $group->path;
		}
		return false;
	}
	
	/**
	 * Compatibility function for BP 1.2 - 1.3 bridge
	 */
	function get_active() {
		if(method_exists(parent,'get_active')) {
			return parent::get_active();
		} else {
			return self::get('active');
		}
	}
	
	function get_by_parent( $parent_id, $type='active', $limit = null, $page = null, $user_id = false, $search_terms = false, $populate_extras = true ) {
		global $wpdb, $bp;

		if ( !is_super_admin() )
			$hidden_sql = $wpdb->prepare( " AND status != 'hidden'");
		
		if( !is_null($search_terms)) {
			$search_terms = like_escape( $wpdb->escape( $search_terms ) );
			$search_sql = " AND ( g.name LIKE '%%{$search_terms}%%' OR g.description LIKE '%%{$search_terms}%%' )";
		}
		
		if ( $limit && $page ) {
			$pag_sql = $wpdb->prepare( " LIMIT %d, %d", intval( ( $page - 1 ) * $limit), intval( $limit ) );
			$total_groups = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT g.id) FROM {$bp->groups->table_name_groupmeta} gm1, {$bp->groups->table_name_groupmeta} gm2, {$bp->groups->table_name} g WHERE g.id = gm1.group_id AND g.id = gm2.group_id AND gm2.meta_key = 'last_activity' AND gm1.meta_key = 'total_member_count' AND g.parent_id = $parent_id {$hidden_sql} {$search_sql}" ) );
		}
		
		switch($type) {
			case 'newest':
				$order_sql = 'ORDER BY g.date_created DESC';
				break;
			case 'popular':
				$order_sql = 'ORDER BY CONVERT(gm1.meta_value, SIGNED) ASC';
				break;
			case 'alphabetical':
				$order_sql = 'ORDER BY g.name ASC';
				break;
			case 'active':
				$order_sql = 'ORDER BY last_activity DESC';
				break;
			default:
				$order_sql = apply_filters('bp_group_hierarchy_directory_order_sort','',$type);
		}
		
		$paged_groups = $wpdb->get_results( $wpdb->prepare( "SELECT g.*, gm1.meta_value as total_member_count, gm2.meta_value as last_activity FROM {$bp->groups->table_name_groupmeta} gm1, {$bp->groups->table_name_groupmeta} gm2, {$bp->groups->table_name} g WHERE g.id = gm1.group_id AND g.id = gm2.group_id AND gm2.meta_key = 'last_activity' AND gm1.meta_key = 'total_member_count' AND g.parent_id = $parent_id {$hidden_sql} {$search_sql} {$order_sql} {$pag_sql}"  ) );

		foreach ( (array)$paged_groups as $key => $group ) {
			$paged_groups[$key] = new BP_Groups_Hierarchy( $group->id );
		}

		if ( !empty( $populate_extras ) ) {
			foreach ( (array)$paged_groups as $group ) $group_ids[] = $group->id;
			$group_ids = $wpdb->escape( join( ',', (array)$group_ids ) );
			$paged_groups = self::get_group_extras( &$paged_groups, $group_ids, 'newest' );
		}

		return array( 'groups' => $paged_groups, 'total' => $total_groups );
	}

	function get_group_extras( $paged_groups, $group_ids, $type = false ) {

		foreach($paged_groups as $key => $group) {
			if(!isset($group->path)) {
				$paged_groups[$key]->path = self::get_path($group->id);
				$paged_groups[$key]->slug = $paged_groups[$key]->path;
			}
		}

		if ( empty( $group_ids ) )
			return $paged_groups;

		return parent::get_group_extras( $paged_groups, $group_ids, $type );
	}
	
	function get_total_subgroup_count( $group_id = null ) {
		global $wpdb, $bp;

		if(is_null($group_id) && isset($this->id)) {
			$group_id = $this->id;
		}

		$group_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$bp->groups->table_name} WHERE parent_id = %d AND status != 'hidden'", $group_id ) );
		return (is_null($group_count)) ? 0 : $group_count;
	}
	
	function __isset($varName) {
		return array_key_exists($varName,$this->vars);
	}
	
	function __set($varName, $value) {
		$this->vars[$varName] = $value;
	}
	
	function __get($varName) {
		return $this->vars[$varName];
	}
	
	
}
?>