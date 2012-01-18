<?php

if( !defined( 'MEDIAWIKI' ) ) {
	die("This file is an extension to the MediaWiki software and cannot be used standalone.\n");
}
 $wgExtensionCredits[defined( 'SEMANTIC_EXTENSION_TYPE' ) ? 'semantic' : 'specialpage'][] = array(
       'path' => __FILE__,
       'name' => 'SemanticAccessControl',
       'author' =>array( '[http://www.mediawiki.org/wiki/User:Jasonzhang Jasonzhang]'),
       'url' => 'https://www.mediawiki.org/wiki/Extension:SemanticAccessControl', 
       'description' => 'An Access framework on top of semantic mediawiki',
       'version'  => 1.0,
       );




$SemanticAccessControl_DIR=dirname(__FILE__);
require_once ("$SemanticAccessControl_DIR/MWUtil.php");
require_once ("$SemanticAccessControl_DIR/SMWUtil.php");


//Map action to required permission String.
$ACL_Action2Permissions = array(
	"view"	  => array('read'),
	"print"	  => array('read'),
	"render"  => array('read'),
	"raw"	  => array('read'),
	"purge"	  => array('read'),
	"watch"	  => array('read'),
	"unwatch" => array('read'),
	"history" => array('read'),
	"read"    => array('read'),
 
	"delete"	=> array('write'),
	"create"	=> array('write'),
	"rollback"	=> array('write'),
	"protect"	=> array('write'),
	"unprotect"	=> array('write'),
	"markpatrolled"	=> array('write'),
	"submit"	=> array('write'),
	"edit"		=> array('write'),	
	"editform"		=> array('write'),
	"editredlink"   => array('write'),
	"move"		=> array('write'),
	"createaccount" => array('write'),
	"autopatrol"    => array('write'),
	"upload"        => array('write'),
	"grant"=>array('grant'), //check a grant permission instead of an action
	"write"=>array("write") //check a write permission instead of an action

);

//what permission is required for a unknow action such as formedit.
$ACL_UNKNOW_ACTION_PERMISSIONS=array("write");

//A namespace to hold UserGroup definition. !!!!!!!!!!!!Do not touch this.
define("ACL_NS_USERGROUP", 160);
define("ACL_USERGROUP", "UserGroup");
$wgExtraNamespaces[ACL_NS_USERGROUP] = ACL_USERGROUP;
$smwgNamespacesWithSemanticLinks[ACL_NS_USERGROUP]=true;

//A namespace to hold ACL definition for a page. !!!!!!!!!!Do not touch this
define("ACL_NS_ACL", 162);
define("ACL_ACL", "ACL");
$wgExtraNamespaces[ACL_NS_ACL] = ACL_ACL;
$smwgNamespacesWithSemanticLinks[ACL_NS_ACL]=true;



// You may need to add custom namepsace to the content namespace list
$ACL_CONTENT_Namespaces=array(NS_MAIN, NS_FILE, NS_USER);
$ACL_SCHEMA_Namespaces=array(NS_CATEGORY, NS_TEMPLATE, SMW_NS_CONCEPT, SMW_NS_PROPERTY, SF_NS_FORM);
$ACL_PERMISSION_Namespaces=array(ACL_NS_USERGROUP, ACL_NS_ACL);

//super group must be native group in MediaWiki
$ACL_supergroups=array("sysop", "bureaucrat", "bot");


//------------------------------You most likely do not need to change anything below.
$wgAutoloadClasses['PermissionTab'] = $SemanticAccessControl_DIR. '/PermissionTab.php';
$wgAutoloadClasses['ACL'] = $SemanticAccessControl_DIR. '/ACL.php';
$wgHooks['MediaWikiPerformAction'][] = 'PermissionTab::displayACLForm';
// 'SkinTemplateNavigation' replaced 'SkinTemplateTabs' in the Vector skin
$wgHooks['SkinTemplateTabs'][] = 'PermissionTab::displayTab';
$wgHooks['SkinTemplateNavigation'][] = 'PermissionTab::displayTab2';
$wgHooks['AdminLinks'][] = 'ACL_addToAdminLinks';

$wgHooks['userCan'][]="ACL::userCan";
# Define a setup function
$wgHooks['ParserFirstCallInit'][] = 'SAC_ACL_parser_setup';
# Add a hook to initialise the magic word
$wgHooks['LanguageGetMagic'][]       = 'SAC_ACL_magic_setup';

function SAC_ACL_parser_setup( $parser ) {

	$parser->setFunctionHook('allusers', 'allusers');
	$parser->setFunctionHook('allgroups', 'allgroups');
	$parser->setFunctionHook('groupusers', 'groupusers');
	$parser->setFunctionHook('usergroups', 'usergroups');
	return true;
}

function SAC_ACL_magic_setup( &$magicWords, $langCode ) {
	$magicWords['allusers']=array(0, 'allusers');
	$magicWords['allgroups']=array(0, 'allgroups');
	$magicWords['groupusers']=array(0, 'groupusers');
	$magicWords['usergroups']=array(0, 'usergroups');
	return true;
}

/**
 *
 * Return all valid groups in the system
 * @param $parser
 */
function allgroups(&$parser, $includepredfined=false)
{
	$grps=array("");
	//$wikigroups=User::getAllGroups();
	$customgroups=MWUtil::allPageInNamespace(ACL_NS_USERGROUP);
	array_push($customgroups, ACL::$AllUser);
	
	$prefinedgroups=ACL::getPredefinedGroups();
	if (!$includepredfined)
	{
		$customgroups=array_diff($customgroups, $prefinedgroups);
	}
	//$grps=array_unique(array_merge($wikigroups, $customgroups));
	$grps=$customgroups;
	sort($grps);
	array_unshift($grps, "");
	return implode(",", $grps);
}

/**
 *
 * Return all users in one group
 *
 * @param $parser
 */
function groupusers(&$parser, $group)
{
	$users=ACL::getGroupUsers($group);
	sort($users);
	return implode(",",$users );
}

/**
 *
 * Return all groups one user belongs to
 *
 * @param $parser
 */
function usergroups(&$parser, $username, $includepredfined=false)
{
	global $wgUser;
	if ($username==='current')
	{
		$username=$wgUser->getName();
	}
	ACL::loadUserGroups();
	$groups=ACL::getUserGroupsByUsername($username);
	$grps=array();
	foreach($groups as $g)
	{
		$grps[]=$g['name'];
	}
	$prefinedgroups=ACL::getPredefinedGroups();
	if (!$includepredfined)
	{
		$grps=array_diff($grps, $prefinedgroups);
	}
	sort($grps);
	return implode(",",$grps);
}

/**
 *
 * Return all user 
 * @param unknown_type $parser
 */
function allusers( &$parser, $includeSuper=false)
{
	global $ACL_supergroups;
	if ($includeSuper)
	{
		return implode(",", MWUtil::getAllUsers());
	} else
	{
		$users=MWUtil::getAllUsers(true);
		$ret=array();
		foreach($users as $username=>$groups)
		{
			if (array_intersect($groups, $ACL_supergroups))
			{
				
			} else
			{
				$ret[]=$username;
			}
		}
		sort($ret);
		return implode(",", $ret);
		
	}
}



/**
 *
 * Hook to add Permission action to page tab.
 * @param unknown_type $admin_links_tree
 */
function ACL_addToAdminLinks( &$admin_links_tree ) {
	$data_structure_label = wfMsg( 'adminlinks_users' ) ;
	$data_structure_section = $admin_links_tree->getSection( $data_structure_label );
	if ( is_null( $data_structure_section ) )
	return true;

	$main_row = $data_structure_section->getRow( 'main' );
	$ul = SpecialPage::getTitleFor( 'FormStart' );

	$params=array("form"=>"ACL UserGroup", "namespace"=>'UserGroup');
	$paramsstr= "form=ACL UserGroup&namespace=UserGroup";
	$main_row->addItem( AlItem::newFromPage( $ul, "Create Custom Group", $params) );

	return true;
}


?>
