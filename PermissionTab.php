<?php

/**
 *
 * Add a permission tab to a article.
 * @author jason
 *
 */
class PermissionTab {

	public static $TAB_TEXT="Permission";
	public static $ACTION="permission";
	public static $FORM="ACL Page Permission";


	/**
	 *
	 * Add a Permission tab. The action associated with this tab is permission
	 */
	static function displayTab( $obj, &$content_actions ) {
		global $wgRequest,  $ACL_CONTENT_Namespaces;
		if ( method_exists ( $obj, 'getTitle' ) ) {
			$title = $obj->getTitle();
		} else {
			$title = $obj->mTitle;
		}
		// Make sure that this is not a special page, and
		// that the user is allowed to edit it
		// - this function is almost never called on special pages,
		// but before SMW is fully initialized, it's called on
		// Special:SMWAdmin for some reason, which is why the
		// special-page check is there.
		if ( !isset( $title ) ||
			( $title->getNamespace() == NS_SPECIAL ) ) {
			return true;
		}
		
		$ns=$title->getNamespace();
		if (!in_array($ns, $ACL_CONTENT_Namespaces))
		{
			//error_log("not display permission tab since namespace is $ns");
			return true;
		}

		if (!$title->exists())
		{
			return true;
		}

		//$user_can_grant =  $obj->mTitle->userCan( 'grant' );
		$user_can_grant=true;
		if (!$user_can_grant)
		{
			//error_log("not display permission tab since user does not have grant pemrission");
			return true;
		}

		$permission_tab = array(
					'class' => ( $wgRequest->getVal( 'action' ) == self::$ACTION) ? 'selected' : '',
					'text' => self::$TAB_TEXT,
					'href' => $title->getLocalURL( "action=".self::$ACTION )
		);

		$content_actions[]=$permission_tab;
		return true;
	}

	/**
	 * Function currently called only for the 'Vector' skin, added in
	 * MW 1.16 - will possibly be called for additional skins later
	 */
	static function displayTab2( $obj, &$links ) {
		// the old '$content_actions' array is thankfully just a
		// sub-array of this one
		$views_links = $links['actions'];
		self::displayTab( $obj, $views_links );
		$links['actions'] = $views_links;
		return true;
	}

	/**
	 * What we should do if the permission action is clicked.
	 * @param OutputPage $output
	 * @param Article $article
	 * @param Title $title
	 * @param User $user
	 * @param WebRequest $request
	 * @Param MediaWiki $wiki
	 */

	static function displayACLForm( $output, $article, $title, $user, $request, $wiki ) {
		global $wgParser;
		if ($request->getVal( 'action' ) != self::$ACTION)
		{
			return true;
		}
			
		$text="";
		$owner=MWUtil::pageOwner($title, true);
		$text.="Page owner is '''".$owner->getName()."'''.";
			
		ACL::loadUserGroups();
		$ownergroups=ACL::getUserGroups($owner);
		
		$ogroups=" Owner belongs to these user groups:";
		if ($ownergroups)
		{
			foreach ($ownergroups as $g)
			{
				$ogroups.=$g['name'].",";
			}
		} else
		{
			$ogroups=" Owner does not belong to any user group";
		}
		$text.=$ogroups."\n\n";
			
		$permissionpage=ACL_ACL.":".$article->getID();
		$permissiontitle=Title::newFromText($permissionpage);
		$ns=$title->getNSText();
		if (!$ns)
		{
			$ns="Main";
		}
		$sp = SpecialPage::getPage("FormEdit");
		$sp_url = $sp->getTitle()->getLocalURL() ;
		$sp_url.="?form=".self::$FORM."&target=$permissionpage&ACL Page Permission[PageId]={$article->getID()}&ACL Page Permission[PageName]={$title->getDBkey()}&ACL Page Permission[Namespace]=$ns";
		
		if ($permissiontitle->exists())
		{
			$text.="[[$permissionpage|View Page Permission]]\n\n----\n";
			$output->addWikiText($text);
			
			$output->addHTML("<a href='$sp_url'>Edit permission for this page</a>");

		} else
		{
			
			$text.="No page specific Permission is set.";
			$output->addWikiText($text);
			$output->addHTML("<a href='$sp_url'>Set permission for this page</a>");
		}
			
		
		 
		return false;
	}
	

}
