<?php
/*
Plugin Name: PMPro Infusionsoft Integration
Plugin URI: http://www.paidmembershipspro.com/pmpro-infusionsoft/
Description: Sync your WordPress users and members with Infusionsoft contacts.
Version: 1.3.1
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/
/*
	Copyright 2011	Stranger Studios	(email : jason@strangerstudios.com)
	GPLv2 Full license details in license.txt
*/

/*
	* XMLRPC lib?
	* options
	- Infusionsoft API Key
	
	If PMPro is not installed:
	- New users should be added with these tags: [ ]
	- Remove members from list when they unsubscribe/delete their account? [ ]
	
	If PMPro is installed:
	* All new users should be added with these tags:
	* New users with no membership should be added with these tags:
	* New users with membership # should be added with these tags: 
	* (Show each level)		
	
	* Provide export for initial import?
*/
define('PMPRO_INFUSIONSOFT_DIR', dirname(__FILE__));
global $pmprois_error_msg;

//init
function pmprois_init()
{
    //get options for below
    $options = get_option("pmprois_options");

    //setup hooks for new users
    if(!empty($options['users_tags']))
        add_action("user_register", "pmprois_user_register");

    //setup hooks for PMPro levels
    pmprois_getPMProLevels();
    global $pmprois_levels;
    if(!empty($pmprois_levels))
    {
        add_action("pmpro_after_change_membership_level", "pmprois_pmpro_after_change_membership_level", 10, 2);
    }

    //pmprois_testConnection();
}
add_action("init", "pmprois_init");

function pmprois_testConnection() {

    global $pmprois_error_msg;

    $options = get_option("pmprois_options", array());
	
	if(empty($options) || empty($options['id']) || empty($options['api_key']))
		return false;
	
    require_once(dirname(__FILE__) . "/includes/isdk.php");
    $app = new iSDK($options['id'], 'infusion', $options['api_key']);
	
	if($app->cfgCon($options['id'], $options['api_key'])) 
	{
		return true;
	} 
	else 
	{
		$pmprois_error_msg = "Connectino to Infusionsoft failed. Check your ID and API Key.";
		return false;
	}    
}

//this is the function that integrates with Infusionsoft
function pmprois_updateInfusionsoftContact($email, $tags = NULL, $otherfields = array())
{
    global $wpdb;

    $options = get_option("pmprois_options");

    //pre tags
    if(!is_array($tags))
    {
        $tags = str_replace(" ", "", $tags);
        $tags = explode(",", $tags);
    }

    require_once(dirname(__FILE__) . "/includes/isdk.php");

    $app = new iSDK($options['id'], "infusion", $options['api_key']);

    $returnFields = array('Id');
    $dups = $app->findByEmail($email, $returnFields);

    //no? add them
    if(empty($dups))
    {
        $contact_id = $app->addCon(array_merge(array("Email"=>$email), $otherfields));
    }
    elseif(is_array($dups))
    {
        $contact_id = $dups[0]['Id'];
        if(!isset($otherfields['Email']))
			$fields = array_merge(array("Email"=>$email), $otherfields);
		else
			$fields = $otherfields;
		$app->updateCon($contact_id, $fields);
    }
    else
        return false;	//probably an error... need some error handling

    if(!empty($contact_id))
    {
        //now that we have an id/contact, lets add all tags
        if(is_array($tags))
        {
            foreach($tags as $tag)
            {
                if(is_numeric($tag))
                {
                    $app->grpAssign($contact_id, $tag);
                }
                else
                {
                    //$group_id = GET GROUP ID	//this feature is not supported by the API
                    //$app->grpAssign($contact_id, $group_id);
                }
            }
        }

        //if this user had an old level, let's remove those tags he doesn't still have
        if(function_exists("pmpro_getMembershipLevelForUser"))
        {
            $user = get_user_by("email", $email);
            $user->membership_level = pmpro_getMembershipLevelForUser($user->ID);
            $old_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_memberships_users WHERE user_id = '" . $user->ID . "' AND membership_id <> '" . $user->membership_level->id . "' AND status = 'inactive' ORDER BY id DESC LIMIT 1");

            if(!empty($old_level))
            {
                $old_tags = $options['level_' . $old_level->membership_id . '_tags'];
                if(!is_array($old_tags))
                {
                    $old_tags = str_replace(" ", "", $old_tags);
                    $old_tags = explode(",", $old_tags);
                }

                if(!empty($old_tags))
                {
                    if(is_array($tags))
                        $old_tags = array_diff($old_tags, $tags);

                    foreach($old_tags as $tag)
                    {
                        if(is_numeric($tag))
                        {
                            $app->grpRemove($contact_id, $tag);
                        }
                        else
                        {
                            //$group_id = GET GROUP ID	//this feature is not supported by the API
                            //$app->grpRemove($contact_id, $group_id);
                        }
                    }
                }
            }
        }

        return $contact_id;
    }
    else
        return false;
}

//subscribe users when they register
function pmprois_user_register($user_id)
{
    $options = get_option("pmprois_options");

    //should we add them to any tags?
    if(!empty($options['users_tags']) && !empty($options['api_key']))
    {
        //get user info
        $list_user = get_userdata($user_id);

        //add/update the contact and assign the tag
        pmprois_updateInfusionsoftContact($list_user->user_email, $options['users_tags'], apply_filters("pmpro_infusionsoft_addcon_fields", array("FirstName"=>$list_user->first_name, "LastName"=>$list_user->last_name), $list_user));
    }
}

//for when checking out
function pmprois_pmpro_after_checkout($user_id)
{
    pmprois_pmpro_after_change_membership_level(intval($_REQUEST['level']), $user_id);
}
add_action("pmpro_after_checkout", "pmprois_pmpro_after_checkout", 30);

//subscribe new members (PMPro) when they register
function pmprois_pmpro_after_change_membership_level($level_id, $user_id)
{
    clean_user_cache($user_id);

    global $pmprois_levels;
    $options = get_option("pmprois_options");

    //should we add them to any tags?
    if(!empty($options['level_' . $level_id . '_tags']) && !empty($options['api_key']))
    {
        //get user info
        $list_user = get_userdata($user_id);

        //add/update the contact and assign the tag
        pmprois_updateInfusionsoftContact($list_user->user_email, $options['level_' . $level_id . '_tags'], apply_filters("pmpro_infusionsoft_addcon_fields", array("FirstName"=>$list_user->first_name, "LastName"=>$list_user->last_name), $list_user));
    }
    elseif(!empty($options['api_key']) && count($options) > 3)
    {
        //now they are a normal user should we add them to any tags?
        if(!empty($options['users_tags']) && !empty($options['api_key']))
        {
            //get user info
            $list_user = get_userdata($user_id);

            //add/update the contact and assign the tag
            pmprois_updateInfusionsoftContact($list_user->user_email, $options['users_tags'], apply_filters("pmpro_infusionsoft_addcon_fields", array("FirstName"=>$list_user->first_name, "LastName"=>$list_user->last_name), $list_user));
        }
        else
        {
            //NOTE: We don't have a way to remove tags from contacts yet
            //some memberships have tags. assuming the admin intends this level to be unsubscribed from everything
            if(is_array($all_tags))
            {
                //get user info
                $list_user = get_userdata($user_id);

                //add/update the contact and assign the tag
                //pmprois_updateInfusionsoftContact($list_user->user_email, $options['users_tags'], apply_filters("pmpro_infusionsoft_addcon_fields", array(), $list_user));
            }
        }
    }
}

//update contact in Infusionsoft if a user profile is changed in WordPress
function pmprois_profile_update($user_id, $old_user_data)
{
    //get user info
    $new_user_data = get_userdata($user_id);

    //get options
    $options = get_option("pmprois_options");

	if(function_exists("pmpro_getMembershipLevelForUser"))
	{
		$user_level = pmpro_getMembershipLevelForUser($user_id);
		if(!empty($user_level))
			$level_id = $user_level->id;
	}
		
    //should we add them to any tags?
    if(!empty($level_id) && !empty($options['level_' . $level_id . '_tags']) && !empty($options['api_key']))
    {
        //get user info
        $list_user = get_userdata($user_id);

        //add/update the contact and assign the tag
        pmprois_updateInfusionsoftContact($old_user_data->user_email, $options['level_' . $level_id . '_tags'], apply_filters("pmpro_infusionsoft_addcon_fields", array("Email"=>$list_user->user_email, "FirstName"=>$list_user->first_name, "LastName"=>$list_user->last_name), $list_user));
    }
    elseif(!empty($options['api_key']) && count($options) > 3)
    {
        //now they are a normal user should we add them to any tags?
        if(!empty($options['users_tags']) && !empty($options['api_key']))
        {
            //get user info
            $list_user = get_userdata($user_id);

			//add/update the contact and assign the tag
			pmprois_updateInfusionsoftContact($old_user_data->user_email, $options['users_tags'], apply_filters("pmpro_infusionsoft_addcon_fields", array("Email"=>$list_user->user_email, "FirstName"=>$list_user->first_name, "LastName"=>$list_user->last_name), $list_user));			                       
        }
        else
        {
            //NOTE: We don't have a way to remove tags from contacts yet
            //some memberships have tags. assuming the admin intends this level to be unsubscribed from everything
            if(is_array($all_tags))
            {
                //get user info
                $list_user = get_userdata($user_id);

                //add/update the contact and assign the tag
                //pmprois_updateInfusionsoftContact($list_user->user_email, $options['users_tags'], apply_filters("pmpro_infusionsoft_addcon_fields", array(), $list_user));
            }
        }
    }		
}
add_action("profile_update", "pmprois_profile_update", 10, 2);

//admin init. registers settings
function pmprois_admin_init()
{
    //setup settings
    register_setting('pmprois_options', 'pmprois_options', 'pmprois_options_validate');
    add_settings_section('pmprois_section_general', 'General Settings', 'pmprois_section_general', 'pmprois_options');
    add_settings_field('pmprois_option_id', 'Infusionsoft Username/ID', 'pmprois_option_id', 'pmprois_options', 'pmprois_section_general');
    add_settings_field('pmprois_option_id', 'Infusionsoft Username/ID', 'pmprois_option_id', 'pmprois_options', 'pmprois_section_general');
    add_settings_field('pmprois_option_api_key', 'Infusionsoft API Key', 'pmprois_option_api_key', 'pmprois_options', 'pmprois_section_general');
    add_settings_field('pmprois_option_users_tags', 'All Users Tags', 'pmprois_option_users_tags', 'pmprois_options', 'pmprois_section_general');

    //pmpro-related options
    add_settings_section('pmprois_section_levels', 'Membership Levels and Lists', 'pmprois_section_levels', 'pmprois_options');

    //add options for levels
    pmprois_getPMProLevels();
    global $pmprois_levels;
    if(!empty($pmprois_levels))
    {
        foreach($pmprois_levels as $level)
        {
            add_settings_field('pmprois_option_memberships_tags_' . $level->id, $level->name, 'pmprois_option_memberships_tags', 'pmprois_options', 'pmprois_section_levels', array($level));
        }
    }
}
add_action("admin_init", "pmprois_admin_init");

//set the pmprois_levels array if PMPro is installed
function pmprois_getPMProLevels()
{
    global $pmprois_levels, $wpdb;
    $pmprois_levels = $wpdb->get_results("SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id");
}

//options sections
function pmprois_section_general()
{
    global $pmprois_error_msg;

    if($pmprois_error_msg) { ?>
         <div class="message error">
            <p>
				<strong>There was an error connecting to your InfusionSoft account:</strong><br>
				<?php echo $pmprois_error_msg; ?>
			</p>
        </div>
    <?php }
}

//options sections
function pmprois_section_levels()
{
    global $wpdb, $pmprois_levels;

    //do we have PMPro installed?
    if(class_exists("MemberOrder"))
    {
        ?>
        <p>PMPro is installed.</p>
        <?php
        //do we have levels?
        if(empty($pmprois_levels))
        {
            ?>
            <p>Once you've <a href="admin.php?page=pmpro-membershiplevels">created some levels in Paid Memberships Pro</a>, you will be able to assign Infusionsoft tags to them here.</p>
        <?php
        }
        else
        {
            ?>
            <p>For each level below, choose the tags which should be added to the contact when a new user registers or switches levels.</p>
        <?php
        }
    }
    else
    {
        //just deactivated or needs to be installed?
        if(file_exists(dirname(__FILE__) . "/../paid-memberships-pro/paid-memberships-pro.php"))
        {
            //just deactivated
            ?>
            <p><a href="plugins.php?plugin_status=inactive">Activate Paid Memberships Pro</a> to add membership functionality to your site and finer control over your Infusionsoft contacts.</p>
        <?php
        }
        else
        {
            //needs to be installed
            ?>
            <p><a href="plugin-install.php?tab=search&type=term&s=paid+memberships+pro&plugin-search-input=Search+Plugins">Install Paid Memberships Pro</a> to add membership functionality to your site and finer control over your Infusionsoft contacts.</p>
        <?php
        }
    }
}

//options code
function pmprois_option_id()
{
    $options = get_option('pmprois_options');
    if(isset($options['id']))
        $id = $options['id'];
    else
        $id = "";
    echo "<input id='pmprois_id' name='pmprois_options[id]' size='80' type='text' value='" . esc_attr($id) . "' />";
}

function pmprois_option_api_key()
{
    $options = get_option('pmprois_options');
    if(isset($options['api_key']))
        $api_key = $options['api_key'];
    else
        $api_key = "";
    echo "<input id='pmprois_api_key' name='pmprois_options[api_key]' size='80' type='text' value='" . esc_attr($api_key) . "' />";
}

function pmprois_option_users_tags()
{
    global $pmprois_all_tags;
    $options = get_option('pmprois_options');

    if(isset($options['users_tags']) && is_array($options['users_tags']))
    {
        $selected_tags = $options['users_tags'];
    }
    elseif(isset($options['users_tags']))
    {
        //probably saved as comma separated string
        $selected_tags = str_replace(" ", "", $options['users_tags']);
        $selected_tags = explode(",", $selected_tags);
    }
    else
        $selected_tags = array();

    if(!empty($pmprois_all_tags))
    {
        echo "<select multiple='yes' name=\"pmprois_options[users_tags][]\">";
        foreach($pmprois_all_tags as $tag_id => $tag)
        {
            echo "<option value='" . $tag_id . "' ";
            if(in_array($tag_id, $selected_tags))
                echo "selected='selected'";
            echo ">" . $tag . "</option>";
        }
        echo "</select>";
    }
    else
    {
        echo "No tags found.";
    }
}

function pmprois_option_memberships_tags($level)
{
    global $pmprois_all_tags;
    $options = get_option('pmprois_options');

    $level = $level[0];	//WP stores this in the first element of an array

    if(isset($options['level_' . $level->id . '_tags']) && is_array($options['level_' . $level->id . '_tags']))
        $selected_tags = $options['level_' . $level->id . '_tags'];
    elseif(isset($options['level_' . $level->id . '_tags']))
    {
        //probably saved as comma separated string
        $selected_tags = str_replace(" ", "", $options['level_' . $level->id . '_tags']);
        $selected_tags = explode(",", $selected_tags);
    }
    else
        $selected_tags = array();

    if(!empty($pmprois_all_tags))
    {
        echo "<select multiple='yes' name=\"pmprois_options[level_" . $level->id . "_tags][]\">";
        foreach($pmprois_all_tags as $tag_id => $tag)
        {
            echo "<option value='" . $tag_id . "' ";
            if(in_array($tag_id, $selected_tags))
                echo "selected='selected'";
            echo ">" . $tag . "</option>";
        }
        echo "</select>";
    }
    else
    {
        echo "No tags found.";
    }
}

// validate our options
function pmprois_options_validate($input)
{
    //api key
    $newinput['id'] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['id']));
    $newinput['api_key'] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['api_key']));

    //user tags
    if(!empty($input['users_tags']) && is_array($input['users_tags']))
    {
        $count = count($input['users_tags']);
        for($i = 0; $i < $count; $i++)
            $newinput['users_tags'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['users_tags'][$i]));	;
    }

    //membership tags
    global $pmprois_levels;
    if(!empty($pmprois_levels))
    {
        foreach($pmprois_levels as $level)
        {
            if(!empty($input['level_' . $level->id . '_tags']) && is_array($input['level_' . $level->id . '_tags']))
            {
                $count = count($input['level_' . $level->id . '_tags']);
                for($i = 0; $i < $count; $i++)
                    $newinput['level_' . $level->id . '_tags'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['level_' . $level->id . '_tags'][$i]));	;
            }
        }
    }

    return $newinput;
}

// add the admin options page	
function pmprois_admin_add_page()
{
    add_options_page('PMPro Infusionsoft Options', 'PMPro Infusionsoft', 'manage_options', 'pmprois_options', 'pmprois_options_page');
}
add_action('admin_menu', 'pmprois_admin_add_page');

//get tags via API
function pmprois_getTags($force = false)
{
    global $pmprois_all_tags;
    $options = get_option("pmprois_options");

    if(isset($pmprois_all_tags) && !$force)
        return $pmprois_all_tags;

    //load api and get tags
    require_once(dirname(__FILE__) . "/includes/isdk.php");
    $app = new iSDK($options['id'], "infusion", $options['api_key']);
    $data = $app->dsQuery('ContactGroup', 1000, 0, array('Id' => '%'), array('Id', 'GroupName'));

    if(!is_array($data))
    {
        //API might have failed, use what we have in settings
        $pmprois_all_tags = get_option('pmprois_all_tags', array());
    }

    //rearrange array so ids are keys
    $pmprois_all_tags = array();    
	if(is_array($data))
	{
		foreach($data as $tag)
			$pmprois_all_tags[$tag['Id']] = $tag['GroupName'];
	}
	else
		return $data;
			
    //Save all of our new data
    update_option( "pmprois_all_tags", $pmprois_all_tags);

    return $pmprois_all_tags;
}

//html for options page
function pmprois_options_page()
{
    global $pmprois_tags, $pmprois_error_msg;

    //check for a valid API key and get tags
    $options = get_option("pmprois_options");
    $api_key = $options['api_key'];
    if(!empty($api_key))
    {
        //get tags
        $tags = pmprois_getTags();
		if(!is_array($tags))
			$pmprois_error_msg = $tags;
    }
    ?>
    <div class="wrap">
        <div id="icon-options-general" class="icon32"><br></div>
        <h2>PMPro Infusionsoft Integration Options</h2>

        <?php if(!empty($msg)) { ?>
            <div class="message <?php echo $msgt; ?>"><p><?php echo $msg; ?></p></div>
        <?php } ?>

        <form action="options.php" method="post">

            <p>This plugin will integrate your site with Infusionsoft. You can enter one or more Infusionsoft tags to have added to your contacts when they signup for your site.</p>
            <p>If you have <a href="http://www.paidmembershipspro.com">Paid Memberships Pro</a> installed, you can also enter one or more Infusionsoft tags to have added to contacts for each membership level.</p>
            <p>Don't have an Infusionsoft account? <a href="http://www.infusionsoft.com/" target="_blank">Get one here</a>.</p>

            <?php settings_fields('pmprois_options'); ?>
            <?php do_settings_sections('pmprois_options'); ?>

            <p><br /></p>

            <div class="bottom-buttons">
                <input type="hidden" name="pmprot_options[set]" value="1" />
                <input type="submit" name="submit" class="button-primary" value="<?php esc_attr_e('Save Settings'); ?>">
            </div>

        </form>
    </div>
<?php
}