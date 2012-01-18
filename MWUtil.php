<?php


/**
 *
 * This class has all the methods interfacing MediaWiki
 * All method should be static.
 * @author jason
 *
 */
class MWUtil
{
	/**
	 *
	 * Add/update a page to wiki
	 * @param string $pagename
	 * @param string $text
	 * @param boolean $hasSemanticInternal whether this text contains semanticInternal Object.
	 * SemanticInternal Object needs some special handling. First there is a bug in current
	 * SemanticInternal Object implemenattion: the semantic internal properties can not be
	 * saved in the very first time. Second, if the semantic internal object saving is not from
	 * a special page action, but a regular page saving action, the semantic internal object
	 * is confused about the "CURRENT" page. So asynchronous saving through a task is needed.
	 *
	 * @param $summary
	 */
	public static function addPage($pagename, $text,  $force=true,  $astask=false, $hasSemanticInternal=false, $summary="Auto generation")
	{
		global $wgUser;
		$title = str_replace( ' ', '_', $pagename);
		$t = Title::makeTitleSafe(NS_MAIN, $title);
		$article=new Article($t);
		if ($article->exists() && !$force)
		{
			return false;
		}
		if (!$astask)
		{
			$e=$article->exists();
			//do adding inline
			$article->doEdit($text, $summary);
			if ($hasSemanticInternal && !$e )
			{
				//one more time
				$article->doEdit($text, $summary);
			}
			return true;
		} else
		{
			//add article asynchronously.
			$jobs = array();
			$job_params = array();
			$job_params['user_id'] = $wgUser->getId();
			$job_params['edit_summary'] =$summary;
			$job_params['text']=$text;
			$jobs[]=new DTImportJob( $t, $job_params );
			if ($hasSemanticInternal && !$article->exists())
			{
				$jobs[]=new DTImportJob( $t, $job_params );
			}
			Job::batchInsert($jobs);
		}
	}
	/**
	 *
	 * Whether the article object in this category.
	 * @param Article $article An article Object
	 * @param string $category string
	 */
	public static function inCategory(&$article, $category)
	{
		$c=self::normalizePageTitle($category, true, NS_CATEGORY);
		$cs=self::getPageCategory($article->getTitle()->getArticleID());
		if (in_array($c, $cs))
		{
			return true;
		} else
		{
			return false;
		}
	}
	/**
	 * 
	 * Whether a page in a particualr category
	 * @param String $titlestr
	 * @param String $category
	 * @param Integer $ns
	 */
	public static function pageInCategory($titlestr, $category, $ns=NS_MAIN)
	{
		$t = Title::makeTitleSafe($ns, $titlestr);
		$article = new Article($t);
		return self::inCategory($article, $category);
	}


	/**
	 *
	 * Enter description here ...
	 * @param String $articleID or titleText
	 * Return a list of categories this article belongs to
	 */
	public static function getPageCategory($page, $ns=NS_MAIN)
	{
		if (!is_int($page))
		{
			$title=Title::makeTitleSafe($ns, $page);
			$article=new Article($title);
			$page=$article->getID();
		}
		
		$result = array();
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( array( 'categorylinks'),
		array( 'cl_to' ),
		array( 'cl_from' => $page),
		__METHOD__ );
		if ( $res !== false ) {
			foreach ( $res as $row ) {
				$result[] = str_replace("_", " ", $row->cl_to);
			}
		}
		$dbr->freeResult( $res );
		
		
		return $result;
	}

	

	 
	/**
	 *
	 * retrieve next ID for a sequence name
	 * @param unknown_type $seqname
	 */
	public static function getNextID($seqname)
	{
		$sql="select bt_seq('$seqname') as id";
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->query($sql, __METHOD__);
			
		$row = $dbr->fetchObject( $res ) ;
		$id=$row->id;
		$dbr->freeResult($res);
		return $id;
	}


	/**
	 *
	 * Enter description here ...
	 * @param string $title
	 * @param int $ns
	 * @param boolean $returnstr
	 * return title string if $returnstr is true, a Title object if $returnstr is false;
	 */
	public static function normalizePageTitle($title,  $returnstr=true, $ns=NS_MAIN)
	{
		$title = str_replace( ' ', '_', $title);
		$t = Title::makeTitleSafe($ns, $title);
		if ($returnstr)
		{
			if ($t && $t->exists())
				return  $t->getDBKey();
			else 
				return $title;
		} else
		{
			return $t;
		}
	}



	/**
	 *
	 * Return all users in the database
	 * @param boolean $withgroup
	 * @return
	 * 	a list of user if $withgroup is false;
	 * otherwise, an associative array with username as key, and an array of groups as value.
	 */
	public static function getAllUsers($withgroup=false)
	{
		$dbr = wfGetDB( DB_SLAVE );
		if (!$withgroup)
		{
			$res = $dbr->select('user',  array('user_name'));
			$users=array();
			while ( $row = $dbr->fetchObject( $res ) )
			{
				$users[]=$row->user_name;
			}
			return $users;
		} else
		{
			//select user_name, user_id , ug_group from user left join  user_groups  on user_id=ug_user;
			$res = $dbr->query("select user_name, user_id , ug_group from user left join  user_groups  on user_id=ug_user", __METHOD__);
				
			$users=array();
			while ( $row = $dbr->fetchObject( $res ) )
			{
				$username=$row->user_name;
				$group=$row->ug_group;
				if ($group)
				{
					if (array_key_exists($username, $users))
					{
						array_push($users[$username], $group);
					} else
					{
						$users[$username]=array($group);
					}
				} else
				{
					$users[$username]=array();
				}
			}
			return $users;
		}
	}



	/**
	 * Return page owner
	 * @param Title $title
	 * @param Boolean $object whether to return a user object or a username.
	 */
	public static function pageOwner( &$title, $object=false)
	{
		global $wgUser;
		if ($title && gettype($title)==='object' && $title->exists())
		{
			if (!$object)
			{
				$author = $title->getFirstRevision()->getUserText(Revision::RAW);
				return $author;
			} else
			{
				$userid=$title->getFirstRevision()->getRawUser();
				return User::newFromId($userid);
					
			}
		} else
		{
			if (!$object)
			{
				return $wgUser->getName();
			} else
			{
				return $wgUser;

			}
		}

	}

	/**
	 *
	 * Return all pages in namespace
	 * @param int $nsid namespace id
	 * @param boolean $returnid whether return page id or pagetitle
	 */
	public static function allPageInNamespace($nsid=0, $returnid=false)
	{
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select('page',  array('page_title', 'page_id'), "page_namespace=$nsid");
		if (!$returnid)
		{
			$titles=array();
				
			while ( $row = $dbr->fetchObject( $res ) )
			{
				$titles[]=$row->page_title;
			}
			return $titles;
		} else
		{
			$ids=array();
				
			while ( $row = $dbr->fetchObject( $res ) )
			{
				$ids[]=$row->page_id;
			}
			return $ids;
		}
	}

	
	/**
	 * 
	 * construct a category tree.
	 * @param a caregory $category
	 */
	public static function &constructCategoryTree($category)
	{
		
		$root=array();
		$titles=array();
		$subs=self::findSubCategories($category, $titles);
		for($i=0; $i<count($subs); $i++)
		{
			$child=self::constructCategoryTree($titles[$i]);
			$root[$subs[$i]]=$child;
		}
		return $root;
		
	}
	
	/**
	 * @param tree tree: A tree returned from constructCategoryTree
	 */
	public static function collectParentCategories(&$tree, $category)
	{
		foreach($tree as $childname =>$childtree)
		{
			if ($childname===$category)
			{
				return array($childname);
			} else if ($childtree)
			{
				$ret=self::collectParentCategories($childtree, $category, NS_CATEGORY);
				if ($ret)
				{
					$ret[]=$childname;
					return $ret;
				} 
			}
		}
		return array();
	}
	
	public static function findSubCategories($category, &$titles=null)
	{
		#select page_title from page, categorylinks where cl_to='Protocols' and cl_from=page_id and page_namespace=14;
		$sortkeys=array();
		
		$dbr = wfGetDB( DB_SLAVE );
		$sql="select cl_sortkey,page_title  FROM page,categorylinks  WHERE cl_to = '{$category}' AND cl_from = page_id AND page_namespace = ".NS_CATEGORY;
		$res = $dbr->query($sql, __METHOD__);
		if ( $res !== false ) {
			foreach ( $res as $row ) {
				$sortkeys[] = $row->cl_sortkey;
				if ($titles!==null)
				{
					$titles[]=$row->page_title;
				}
			}
		}
		$dbr->freeResult( $res );
		return $sortkeys;
	}
	
	
	public static function getProperty(&$props, $propname)
	{
		if ($props===null)
		{
			printstack();
		}
		$v=array_key_exists($propname, $props)?$props[$propname]:"";
		if (is_array($v))
		{
			return implode(",", $v);
		} else
		{
			return $v;
		}
	}
}

?>
