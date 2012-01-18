<?php
function _myupper($name)
{
	return ucfirst(trim($name));
}
/**
 *
 * contain the ACL loading and check logic.
 * @author jason
 *
 */
class ACL
{


	//a cache object. All UserGroup objects.
	/*
	* The key is group name. The value is the semantic property associated with the page unde
	* UserGroup namespace.
	* The important key is
	* name->group name
	* User->a list of user name in this group
	* Permissions->a list of permission object
	* 	Each Permission object
	* 		Permissions->a list of permission string
	* 		Grant->Grant|Reject
	*
	*/
	protected static $allGroups=null;
	//A cache object. User->a list of usergroup, this user belongs to.
	protected static $userGroups=array();

	protected static $DefaultACL="SiteACL";
	protected static $DEVELOPERS="Developers";
	protected static $GroupACL="GroupACL";
	public static $AllUser="All Users";


	/*
	 * Constant used in Template:ACL_Page_Permission
	 */
	protected static $PAGE_PAGE_ID="ACL PageId";
	protected static $PAGE_PAGE_NAME="ACL PageName";
	protected static $PAGE_PAGE_NS="ACL Namespace";
	protected static $PAGE_GROUP="ACL Group permission for page";
	protected static $PAGE_GROUP_GROUP="ACL UserGroup";
	protected static $PAGE_USER="ACL User permission for page";
	protected static $PAGE_USER_USER="ACL User";

	/*
	 * Constant used in page itself.
	 * Most likely these property is set automatically in SM template.
	 */
	protected static $CONTENT_PAGE_PARENT="ACL Page Parent";
	protected static $CONTENT_PAGE_OWNER="ACL Page Owner";
	protected static $CONTENT_PAGE_Group="ACL Page Group";
	protected static $CONTENT_PAGE_FIXED="ACL Page FXIED";


	/*
	 * Constant used in Template:UserGroup
	 */
	public static $USERGROUP_USERS="ACL User";
	protected static $USERGROUP_PERMISSIONS="ACL Permission for a group";
	public static $USERGROUP_GROUPLEADER="ACL UserGroup Leader";

	protected static $PERMISSIONS="ACL Permissions";
	protected static $GRANT="ACL Grant";


	protected static $WRITE_PERM="write";
	protected static $READ_PERM="read";
	protected static $GRANT_PERM="grant";


	protected static $GRANT_ACCESS_ALLOW="Grant";
	protected static $GRANT_ACCESS_DENY="Reject";

	public static function getPredefinedGroups()
	{
		return array(self::$DefaultACL, self::$DEVELOPERS, self::$GroupACL, self::$AllUser);
	}
	/**
	 *
	 * Enter description here ...
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param Boolean $result
	 */
	public static function userCan( &$title, &$user, $action, &$result )
	{
		if (!$user->isLoggedIn())
		{
			//Let wiki decide it for non login user
			return true;
		}
		$pagename=$title->getNsText().":".$title->getDBkey();
		$ret=self::checkPagePermission($user, $action, $title);
		if ($ret===true)
		{
			$result=true;
			//error_log("YYYYY----give  {$user->getName()} $action for $pagename");
			return false;
			//we can give access
		}	else if ($ret===false)
		{
			$result=false;
			//error_log("XXXXX----reject {$user->getName()} $action for $pagename");

			return false;
		} else
		{
			//Let wiki decide it.
			return true;
		}
	}


	/**
	 *
	 * Check whether the $user can permform the $action on $article or not
	 * @param User $user
	 * @param string $action
	 * @param Title $title
	 * @return
	 * 	true if the user can access.
	 * 	false if the user can not acces
	 *  -1 if this rule does not apply
	 *
	 */
	public static function checkPagePermission($user, $action, $title)
	{
		$username=$user->getName();
		$pagetitle=$title->getNsText().":".$title->getDBkey();
		//error_log("          Can $username $action $pagetitle?");




		global $ACL_CONTENT_Namespaces, $ACL_SCHEMA_Namespaces, $ACL_PERMISSION_Namespaces;
		//super short cut.
		$ret=self::superShortCut($title, $user, $action);
		if (is_bool($ret))
		{
			//error_log("            return |$ret| by SUPER short cut");
			return $ret;
		}

		self::loadUserGroups();
		$nsid=$title->getNamespace();
		if (in_array($nsid, $ACL_SCHEMA_Namespaces))
		{
			$ret=self::checkUserPermissionForSchemaPage($user, $action, $title);
			//error_log("            return |$ret| for SCHEMA page");
			return $ret;
		}

		if (in_array($nsid, $ACL_PERMISSION_Namespaces))
		{
			$ret=self::checkUserPermissionForPermissionPage($user, $action, $title);
			//error_log("            return |$ret| for permission page");
			return $ret;
		}

		if (in_array($nsid, $ACL_CONTENT_Namespaces))
		{
			$result=1;
			wfRunHooks("aclCheckPagePermission", array($user, $action, $title, $result));
			if (is_bool($result))
			{
				return $result;
			}
			if ($result!==1)
			{
				return -1;
			}
			$allAllowed=true;
			$permissions=self::getActionPermission($action, $title);
			foreach ($permissions as $permission)
			{
				$ret=self::checkUserPermissionForContentPage($user, $title, $permission);
				if ($ret===false)
				{
					//one of the required permission is denied.
					//error_log("            return |$ret| for content page.");
					return false;
				} else if ($ret===-1)
				{
					//can not decide one of the permission
					$allAllowed=false;
				}
			}
			if ($allAllowed)
			{
				//permission is satisfied
				//error_log("            return true for content page.");
				return true;
			}
			//error_log("             return |-1| for content page.");
			return -1;
		}


		//error_log("             do not touch unknown namespace $nsid");
		return -1;
	}


	/**
	 *
	 * Whether we could grant access based  on some super short cut rule.
	 *  Step 1: for page with page_id <=0, we grant access.  These are the skin pages, javascript pages, css pages.
	 Step 2:  Administrator privilege: First, if the user in a super groups (bot, sysop, bureaucrat), the user has all the permissions. This is like the root privilege in OS. If the user belongs to these group, permission is granted. No further check is performed.
	 Step 3: Owner permission. If the end user is owner, the end user is granted access. Not further check is performed.
	 * @param Title $title
	 * @param User $requester current request user
	 * @return
	 * 	true if the user can access.
	 * 	false if the user can not acces
	 *  -1 if this rule does not apply
	 */
	public static function superShortCut($title, $requester, $action)
	{

		//super user rule.
		$issuperuser=self::isSuperUser($requester);
		if ($issuperuser && $title->getNamespace()!=ACL_NS_ACL)
		{
			//error_log("   ***return true for the super user rule|$issuperuser|");
			return true;
		}


		if ($title->exists())
		{
			//step 1: system article
			if ($title->getArticleID() <=0 )
			{
				//error_log("return true since article id is {$title->getArticleID()}");
				return true;
			}


			//write is needed
			//special consideration for page that can not be editted such as Purchase Order.
			$permssions=self::getActionPermission($action, $title);
			if (in_array(self::$WRITE_PERM, $permssions))
			{
					
				$pageprops=SMWUtil::loadSemanticProperties($title->getDBkey(), $title->getNamespace(), false);
				if (array_key_exists(self::$CONTENT_PAGE_FIXED, $pageprops))
				{
					$fixed=$pageprops[self::$CONTENT_PAGE_FIXED];
					if ($fixed=='Yes' || $fixed=='yes')
					{
						return false;
					} else
					{
						return true;
					}
				}

			}
			//owner has every right.
			$owner=MWUtil::pageOwner($title, true);
			if ($requester->getId()===$owner->getId())
			{
				//error_log("return true for owner");
				return true;
			}

			return -1;
		} else
		{
			return -1;
		}

	}




	/**
	 *
	 * Whether a user has permission to perform the action on a schema page.
	 * Very simple practical rule. Never let the wiki itself to decide the permission.
	 * @param User $user
	 * @param string $action
	 * @param Title $title
	 * @return
	 * 	true if the user can access.
	 * 	false if the user can not acces
	 *  -1 if this rule does not apply
	 */
	public static function checkUserPermissionForSchemaPage($user, $action, $title)
	{
		$username=$user->getName();
		$permssions=self::getActionPermission($action, $title);

		//write is needed
		if (in_array(self::$WRITE_PERM, $permssions))
		{
			$g= self::getPredefinedDevelopersGroup();
			$groupusers=$g[self::$USERGROUP_USERS];
			if (in_array($username,$groupusers))
			{
				//developer have write permission.
				return true;
			} else
			{
				//non developer does not have write permission
				return false;
			}
		} else
		{
			//read is needed.
			return true;
		}
		//There is no grant permission for schema page.
	}


	/**
	 *
	 * Whether a user has permission
	 * 	If targeted content does not exist, no action is allowed.
	 * @param User $user
	 * @param string $action
	 * @param Title $title
	 *
	 ** @return
	 * 	true if the user can access.
	 * 	false if the user can not acces
	 *  -1 if this rule does not apply
	 */
	public static function checkUserPermissionForPermissionPage($user, $action, $title)
	{
		//for non-page-specific permission specification.
		//in current implementation, it is UserGroup.
		if ($title->getNamespace()!=ACL_NS_ACL)
		{
			//when flow come here, the user is definitely regular user, not super user
			if(!$title->exists())
			{
				return false;
			} else
			{
				$permssions=self::getActionPermission($action, $title);
				if (in_array(self::$WRITE_PERM, $permssions))
				{
					$props=self::$allGroups[$title->getDBkey()];
					if (array_key_exists(self::$USERGROUP_GROUPLEADER, $props))
					{
						$leader=$props[self::$USERGROUP_GROUPLEADER];
						if ($leader==$user->getName())
						{
							//group leader has control on the usergroup page.
							return true;
						} else
						{
							//otherwise does not have control on the usergroup page.
							return false;
						}
					} else
					{
						return false;
					}
				} else
				{
					return  true;
				}
			}

		}
		//when flow comes to here, we check only page-specific permission page
		$username=$user->getName();
		$supportedPageid=$title->getDBkey();
		$supportedTitle=Title::newFromID($supportedPageid);
		if ($supportedTitle==null || !$supportedTitle->exists())
		{
			//we can not operate permission for a nonexist content page.
			//error_log("      return false  for permission page for nonexistent content page.");
			return false;
		}

		$smprops=SMWUtil::loadSemanticProperties($title->getDBkey(), $title->getNamespace(), false);
		$permssions=self::getActionPermission($action, $title);
		if (in_array(self::$WRITE_PERM, $permssions))
		{
			$ret= self::checkPagePermission($user, self::$GRANT_PERM,  $supportedTitle);
			if ($ret===-1)
			{
				$ret=false;
			}
			//error_log("      return |$ret| for  permission page(grant) .");
			return $ret;
		} else
		{
			//read is needed.
			$ret=self::checkPagePermission($user,  self::$READ_PERM,  $supportedTitle);
			//error_log("      return |$ret| for permission page (write)");
			return $ret;
		}

	}



	/**
	 *
	 * Go through all the level of ACL to check whether a user has the $permission on the
	 * particular article.
	 * @param User $user
	 * @param Title $title
	 * @param String $permission required permission.
	 * @param Boolean $fromchild whether this method is invoked from a logic child page.
	 * The child page delegates the parent page to check the permission for itself.
	 * @return
	 * 	true if the user can access.
	 * 	false if the user can not acces
	 *  -1 if this rule does not apply
	 */
	public static function checkUserPermissionForContentPage($user, $title, $permission, $fromchild=false)
	{
		//error_log("check content permission for {$title->getDBkey()}");
		if (!$title->exists())
		{
			//let wiki to decide who can create page.
			//error_log("return -1 for nonexists content article");
			return -1;
		}
		self::loadUserGroups();
		$username=$user->getName();


		/*
		 * Step 1.1
		 * If the page has a logic page owner, this page owner has all permission with this page.
		 */
		$pageprops=SMWUtil::loadSemanticProperties($title->getDBkey(), $title->getNamespace(), false);
		if (array_key_exists(self::$CONTENT_PAGE_OWNER, $pageprops))
		{
			$pageowner=$pageprops[self::$CONTENT_PAGE_OWNER];
			if(is_array($pageowner))
			{
				$pageowner=array_map("_myupper", $pageowner);
				if (in_array($username, $pageowner))
				{
					return true;
				}
			} else
			{
				$pageowner=ucfirst($pageowner);
				if ($pageowner===$username)
				{
					return true;
				}
			}
		}

		/*
		 * Step 1.1.1
		 * check the ACL rule embedded  in page content.
		 */
		$allgrouppermission=null;
		$pps=self::loadPageSpecificPermissions($title, true);
		//1.1.1.1: check ACL rule for user in page content.
		if ($pps!=null)
		{
			foreach($pps[self::$PAGE_USER] as $ups)
			{
				//An ACL for this user.
				if ($ups[self::$PAGE_USER_USER]===$username)
				{
					if (in_array($permission, $ups[self::$PERMISSIONS]))
					{
						if ($ups[self::$GRANT]==self::$GRANT_ACCESS_ALLOW)
						{
							return true;
						} else
						{
							return false;
						}
					}
				}
			}




			//1.1.1.2: check ACL rule for group in page content.
			foreach ($pps[self::$PAGE_GROUP] as $gps)
			{
				$groupname=$gps[self::$PAGE_GROUP_GROUP];
				if ($groupname===self::$AllUser)
				{
					//delay all groups permission setting so that permission in ACL page can be effective.
					$allgrouppermission=$gps;
					continue;
				}
				$groupDefinition=self::$allGroups[$groupname];
				if (!$groupDefinition)
				{
					//group is deleted.
					continue;
				}

				//if the user in the group.
				if (in_array($username, $groupDefinition[self::$USERGROUP_USERS]))
				{
					if (in_array($permission, $gps[self::$PERMISSIONS]))
					{
						if ($gps[self::$GRANT]==self::$GRANT_ACCESS_ALLOW)
						{
							return true;
						} else
						{
							return false;
						}
					}
				}

			}
		}


		/*
		 *  Step 1.2
		 *  Page-specific permission. Each page can have user-specific or
		 *  group-specific ACL. If the current user is one of the user, or belongs
		 *  to one of groups. The corresponding permission is checked.
		 */
		//check page-spefici user rule.
		$pps=null;
		$pps=self::loadPageSpecificPermissions($title);
		if ($pps!=null)
		{
			foreach($pps[self::$PAGE_USER] as $ups)
			{
				//An ACL for this user.
				if ($ups[self::$PAGE_USER_USER]===$username)
				{
					if (in_array($permission, $ups[self::$PERMISSIONS]))
					{
						if ($ups[self::$GRANT]==self::$GRANT_ACCESS_ALLOW)
						{
							return true;
						} else
						{
							return false;
						}
					}
				}
			}


			//check page-specific group rule
			foreach ($pps[self::$PAGE_GROUP] as $gps)
			{
				$groupname=$gps[self::$PAGE_GROUP_GROUP];
				if ($groupname===self::$AllUser)
				{
					if (in_array($permission, $gps[self::$PERMISSIONS]))
					{
						if ($gps[self::$GRANT]==self::$GRANT_ACCESS_ALLOW)
						{
							return true;
						} else
						{
							return false;
						}
					}
					continue;
				}
				$groupDefinition=self::$allGroups[$groupname];
				if (!$groupDefinition)
				{
					//group is deleted.
					continue;
				}

				//if the user in the group.
				if (in_array($username, $groupDefinition[self::$USERGROUP_USERS]))
				{
					if (in_array($permission, $gps[self::$PERMISSIONS]))
					{
						if ($gps[self::$GRANT]==self::$GRANT_ACCESS_ALLOW)
						{
							return true;
						} else
						{
							return false;
						}
					}
				}

			}
		}


		//check the all group permission in the content page.
		if ($allgrouppermission!=null)
		{
			if (in_array($permission, $allgrouppermission[self::$PERMISSIONS]))
			{
				if ($allgrouppermission[self::$GRANT]==self::$GRANT_ACCESS_ALLOW)
				{
					return true;
				} else
				{
					return false;
				}
			}
		}

		/*
		 * Step 1.3
		 * If the page has a logic ACl page parent, we delegates permission check to that page.
		 */
		$pageparent=null;
		if (array_key_exists(self::$CONTENT_PAGE_PARENT, $pageprops))
		{
			$pageparent=$pageprops[self::$CONTENT_PAGE_PARENT];
		}
		if ($pageparent)
		{
			$parenttitle=Title::newFromURL($pageparent);
			$ret=self::checkUserPermissionForContentPage($user, $parenttitle, $permission, true);
			return $ret;
		}



		/*
		 * Step 2. group permission.
		 * Each page has an owner. For example, the owner belongs to  both sale and R&D groups.
		 *  If the current user belongs to one of the group, the group ACL is checked.  We grant
		 *   access if the user has permission.
		 *
		 * Otherwise, we check the pre-defined default group ACL, if the user has
		 * necessary permission, we grant access. Otherwise, we go to next step.
		 */
		$owner=MWUtil::pageOwner($title, true);
		if (array_key_exists(self::$CONTENT_PAGE_Group, $pageprops))
		{
			//if the page has specified its own group we use it.
			$ogrps=$pageprops[self::$CONTENT_PAGE_Group];
			if (is_array($ogrps))
			{
				$ownerGroups=$ogrps;
			} else
			{
				$ownerGroups=array($ogrps);
			}
		} else
		{
			//otherwise, we retrieve the create group
			$ownerGroups=self::getUserGroups($owner);
		}
		$inownergroup=false;
		$checkDefaultACL=false;

		//check the permission in owner group
		foreach($ownerGroups as $ownerGroup)
		{
			if (!in_array($username, $ownerGroup[self::$USERGROUP_USERS]))
			{
				continue;
			}
			$inownergroup=true;
			if ($ownerGroup['name']==self::$DefaultACL)
			{
				$checkDefaultACL=true;
			}

			$ret=self::checkGroupRule($permission, $ownerGroup);
			//error_log("          $ret for  owner group");
			if (is_bool($ret))
			{
				return $ret;
			}
		}

		//check the permission in default group
		if ($inownergroup)
		{
			$ret=self::checkGroupRule($permission, self::getPredefinedGroupACLGroup());
			//error_log("          $ret for  default group ACL");
			if (is_bool($ret))
			{
				return $ret;
			}
		}

		/*
		 *Step 3 Global rule.  Check 'Users' access control rule.
		 */
		if (!$checkDefaultACL)
		{
			$ret=self::checkGroupRule($permission, self::getPredefinedDefaultACLGroup());
			//error_log("          $ret for  default ACL");
			if (is_bool($ret))
			{
				return $ret;
			}
		}
		/*
		 *  Step 4. If we comes to this step, there is no rule defined for this user.
		 *   We deny access by default.
		 */
		return -1;
	}

	private  static function checkGroupRule($permission, $group)
	{
		//error_log(print_r($group, true));
		$allowed=false;
		foreach ($group[self::$USERGROUP_PERMISSIONS] as $p)
		{
			if (in_array($permission, $p[self::$PERMISSIONS]))
			{
				if ($p[self::$GRANT]!=self::$GRANT_ACCESS_ALLOW)
				{
					//denied.
					return false;
				} else
				{
					$allowed=true;
				}
			}
		}

		if ($allowed)
		{
			//not denied , and allowed
			return true;
		}
		return -1;
	}

	public static function getGroupUsers($group)
	{
		self::loadUserGroups();

		if (array_key_exists($group,self::$allGroups))
		{
			$props=self::$allGroups[$group];
			return $props[self::$USERGROUP_USERS];
		} else
		{
			return array();
		}


	}

	//------------------------------------Load rule
	/**
	*
	* @return a list . Each list has three props.
	* 	name: the group name.
	*  User: the user this group belongs to.  The value should be a list.
	*  Permission: default permission this group has. The value should be a list.
	*
	*/
	public static function loadUserGroups()
	{
		if (self::$allGroups!=null)
		{
			return self::$allGroups;
		}

		$tempgroups=array();
		wfRunHooks("aclLoadGroups", $tempgroups);

		self::$allGroups=array();
		$allpages=MWUtil::allPageInNamespace(ACL_NS_USERGROUP);
		foreach ($allpages as $pagename)
		{
			$props=SMWUtil::loadSemanticProperties($pagename, ACL_NS_USERGROUP, false);

			//------handle group leader.
			if (!array_key_exists(self::$USERGROUP_GROUPLEADER, $props))
			{
				$props[self::$USERGROUP_GROUPLEADER]="";
			}

			//--------load the users in groups.
			if(array_key_exists(self::$USERGROUP_USERS, $props))
			{
				//if there is only one user, convert the string into element of one element.
				if (!is_array($props[self::$USERGROUP_USERS]))
				{
					$props[self::$USERGROUP_USERS]=array($props[self::$USERGROUP_USERS]);
				}
			} else
			{
				$props[self::$USERGROUP_USERS]=array();
			}

			//merge group leader into users
			if ($props[self::$USERGROUP_GROUPLEADER])
			{
				array_push($props[self::$USERGROUP_USERS], $props[self::$USERGROUP_GROUPLEADER]);
				$props[self::$USERGROUP_USERS]=array_unique($props[self::$USERGROUP_USERS]);
			}

			//merge the user loading externally.
			if (array_key_exists($pagename, $tempgroups))
			{
				$props[self::$USERGROUP_USERS]=array_unique(array_merge($tempgroups[$pagename], $props[self::$USERGROUP_USERS]));
			}

			//handle permission
			$ps=SMWUtil::getSemanticInternalObjects(ACL_USERGROUP.":".$pagename, self::$USERGROUP_PERMISSIONS, ACL_NS_USERGROUP);
			$permissions=array();
			foreach($ps as $p)
			{
				if (!$p)
				continue;
				//convert ACL PERMISSIONS to an array
				if (array_key_exists(self::$PERMISSIONS,$p))
				{
					$p[self::$PERMISSIONS]= array_map("trim", explode(",",$p[self::$PERMISSIONS]));
					$permissions[]=$p;
				}
			}
			$props[self::$USERGROUP_PERMISSIONS]=$permissions;


			$props['name']=$pagename;
			self::$allGroups[$pagename]=$props;
		}
		return self::$allGroups;
	}

	public static function getPredefinedDefaultACLGroup()
	{
		self::loadUserGroups();
		return self::$allGroups[self::$DefaultACL];
	}
	public static function getPredefinedDevelopersGroup()
	{
		self::loadUserGroups();
		return self::$allGroups[self::$DEVELOPERS];
	}
	public static function getPredefinedGroupACLGroup()
	{
		self::loadUserGroups();
		return self::$allGroups[self::$GroupACL];
	}


	/**
	 *
	 * Load page-specific permission settings.
	 * @param Title $title
	 * @param Boolean pageself: whether to lookup the rule in pageitself or in the acl page.
	 * 		Return a associative array
	 * 		groupperms->{Grant:Grant|Reject,UserGroup, Permissions->(read, write..)}
	 * 		userperms->{Grant:Grant|Reject,User,Permissions->(read,write..)}
	 */
	public static function loadPageSpecificPermissions($title, $pageself=false)
	{
		if ($pageself)
		{
			$pageProps=array();
			if (!$title->exists())
			{
				return null;
			}
			$fullname=$title->getNsText().":".$title->getDBkey();
			$groupPermissions=SMWUtil::getSemanticInternalObjects($fullname, self::$PAGE_GROUP, $title->getNamespace());
			$gps=array();
			foreach ($groupPermissions as $gp)
			{
				//convert permission to an array
				$gp[self::$PERMISSIONS]= array_map("trim", explode(",",$gp[self::$PERMISSIONS]));
				$gps[]=$gp;
			}

			$userPermissions=SMWUtil::getSemanticInternalObjects($fullname, self::$PAGE_USER, $title->getNamespace());
			$ups=array();
			foreach ($userPermissions as $up)
			{
				$up[self::$PERMISSIONS]=array_map("trim", explode(",", $up[self::$PERMISSIONS]));
				$ups[]=$up;
			}


			$pageProps[self::$PAGE_GROUP]=$gps;
			$pageProps[self::$PAGE_USER]=$ups;

			//error_log(print_r($pageProps, true));
			return $pageProps;

		} else
		{
			$id=$title->getArticleID();
			$aclTitle=Title::newFromText($id, ACL_NS_ACL);
			if (!$aclTitle->exists())
			{
				return null;
			}
			$pageProps=SMWUtil::loadSemanticProperties($id, ACL_NS_ACL, false);
			$aclpagename=ACL_ACL.":".$id;


			$groupPermissions=SMWUtil::getSemanticInternalObjects($aclpagename, self::$PAGE_GROUP, ACL_NS_ACL);
			$gps=array();
			foreach ($groupPermissions as $gp)
			{
				//convert permission to an array
				$gp[self::$PERMISSIONS]= array_map("trim", explode(",",$gp[self::$PERMISSIONS]));
				$gps[]=$gp;
			}

			$userPermissions=SMWUtil::getSemanticInternalObjects($aclpagename, self::$PAGE_USER, ACL_NS_ACL);
			$ups=array();
			foreach ($userPermissions as $up)
			{
				$up[self::$PERMISSIONS]=array_map("trim", explode(",", $up[self::$PERMISSIONS]));
				$ups[]=$up;
			}


			$pageProps[self::$PAGE_GROUP]=$gps;
			$pageProps[self::$PAGE_USER]=$ups;
			return $pageProps;
		}
	}

	//------------------------------Helper function

	public static function getUserGroups($user)
	{
		return self::getUserGroupsByUsername($user->getName());
	}
	/**
	 *
	 * Return a list of UserGroup this user belongs to
	 * @param User $user
	 */
	public static function getUserGroupsByUsername($username)
	{

		if (array_key_exists($username, self::$userGroups))
		{
			return self::$userGroups[$username];
		}

		self::loadUserGroups();
		$mygroups=array();
		$defaultacl_selected=false;

		foreach (self::$allGroups as $grpname=>$group)
		{

			if (in_array($username, $group[self::$USERGROUP_USERS]))
			{
				$mygroups[]=$group;
				if ($group['name']===self::$DefaultACL)
				{
					$defaultacl_selected=true;
				}
			}
		}

		//all user belongs to this group.
		if (!$defaultacl_selected)
		{
			$mygroups[]=self::getPredefinedDefaultACLGroup();
		}
		self::$userGroups[$username]=$mygroups;
		return $mygroups;
	}

	/**
	 *
	 * Decide what permssion is required to operate on the action.
	 * @param String $action
	 * @param Article $article
	 */
	public static function getActionPermission($action, $title)
	{
		global $ACL_Action2Permissions, $ACL_UNKNOW_ACTION_PERMISSIONS;
		$ret="";
		if (array_key_exists($action,  $ACL_Action2Permissions))
		{
			$ret= $ACL_Action2Permissions[$action];
		} else
		{
			$ret=$ACL_UNKNOW_ACTION_PERMISSIONS;
		}
		return $ret;
	}

	/**
	 *
	 * Judge whether a user is the super user.
	 * @param User $user
	 */
	public static function isSuperUser($user)
	{
		global $ACL_supergroups;
		//error_log("user groups".print_r($user->getGroups(), true));
		$commongroups=array_intersect($user->getGroups(), $ACL_supergroups);
		if ($commongroups)
		{
			return true;
		} else
		{
			return false;
		}
	}




}


?>
