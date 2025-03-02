<?php
if($_SERVER['REQUEST_METHOD']==='HEAD'){
	exit; // ignore headers, no further processing needed
}
require_once(__DIR__.'/../common_config.php');
try{
	$db=new PDO('mysql:host=' . DBHOST . ';dbname=' . DBNAME . ';charset=utf8mb4', DBUSER, DBPASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING, PDO::ATTR_PERSISTENT=>PERSISTENT]);
}catch(PDOException $e){
	http_response_code(500);
}
date_default_timezone_set('UTC');
//select output format
if(!isset($_REQUEST['format'])){
	send_html();
}elseif($_REQUEST['format']==='text'){
	send_text();
}elseif($_REQUEST['format']==='json'){
	send_json();
}else{
	send_html();
}

function send_html(): void
{
	global $categories, $db, $language, $dir, $locale, $canonical_query;
	$numrows = 0;
	$not_found = false;
	$style = '.row{display:flex;flex-wrap:wrap}.headerrow{font-weight:bold}.col{display:flex;flex:1;padding:3px 3px;flex-direction:column}';
	$style .= '.red{color:red}.green{color:green}.up .col:nth-child(0n+3){background-color:#aaff88}.down .col:nth-child(0n+3){background-color:#ff4444}';
	$style .= '.promo{outline:medium solid #FFD700}.list{padding:0;}.list li{display:inline-block;padding:0.35em}.pagination{font-size:1.2em}';
	$style .= '.active{font-weight:bold}#maintable .col{word-break:break-all;min-width:6em}#maintable,#maintable .col{border: 1px solid black}';
	$style .= '#maintable .col:nth-child(0n+2){flex-grow:3;min-width:12em} #maintable .col:nth-child(0n+3){word-break:unset}#maintable .col:last-child{max-width:6em}.software-link{text-align:center;font-size:small}';
	send_headers([$style]);
	asort($categories);
	//sql for special categories
	$special=[
		_('All legitimate')=>"address!='' AND category!=15 AND id NOT IN (SELECT onion_id FROM " . PREFIX . 'phishing) AND timediff<604800',
		_('Last added')=>"address!='' AND id NOT IN (SELECT onion_id FROM " . PREFIX . 'phishing)',
		_('Offline > 1 week')=>"address!='' AND id NOT IN (SELECT onion_id FROM " . PREFIX . 'phishing) AND timediff>604800'
	];
	$canonical_query = [];
	if(!isset($_REQUEST['pg'])){
		$_REQUEST['pg']=1;
	}else{
		settype($_REQUEST['pg'], 'int');
		if($_REQUEST['pg'] !== 1) {
			$canonical_query[ 'pg' ] = $_REQUEST[ 'pg' ];
		}
	}
	$category=count($categories);
	if(isset($_REQUEST['cat']) && $_REQUEST['cat']<(count($categories)+count($special)+1) && $_REQUEST['cat']>=0){
		settype($_REQUEST['cat'], 'int');
		$category=$_REQUEST['cat'];
		if($category !== count($categories)) {
			$canonical_query[ 'cat' ] = $category;
		}
	}
	$pages=1;
	$admin_approval = '';
	if(REQUIRE_APPROVAL){
		$admin_approval = PREFIX . 'onions.approved = 1 AND';
	}
	$category_count = [];
	$cat=count($categories);
	if($db instanceof PDO) {
		foreach ( $special as $name => $query ) {
			if ( $name === _('Last added') ) {
				$category_count[ $cat ] = PER_PAGE;
			} else {
				$category_count[ $cat ] = $db->query( 'SELECT COUNT(*) FROM ' . PREFIX . "onions WHERE $admin_approval $query;" )->fetch( PDO::FETCH_NUM )[ 0 ];
			}
			if ( $category == $cat ) {
				$pages = ceil( $category_count[ $cat ] / PER_PAGE );
			}
			++$cat;
		}
		$category_count[ $cat ] = $db->query( 'SELECT COUNT(*) FROM ' . PREFIX . 'phishing, ' . PREFIX . 'onions WHERE ' . "$admin_approval " . PREFIX . "onions.id=onion_id AND address!='' AND timediff<604800;" )->fetch( PDO::FETCH_NUM )[ 0 ];
		$category_count[ 'removed' ] = $db->query( 'SELECT COUNT(*) FROM ' . PREFIX . "onions WHERE address='';" )->fetch( PDO::FETCH_NUM )[ 0 ];
		if ( REQUIRE_APPROVAL ) {
			$category_count[ 'pending' ] = $db->query( 'SELECT COUNT(*) FROM ' . PREFIX . "onions WHERE approved = 0 AND address!='';" )->fetch( PDO::FETCH_NUM )[ 0 ];
			$category_count[ 'rejected' ] = $db->query( 'SELECT COUNT(*) FROM ' . PREFIX . "onions WHERE approved = -1 AND address!='';" )->fetch( PDO::FETCH_NUM )[ 0 ];
		}
		$stmt = $db->prepare( 'SELECT COUNT(*) FROM ' . PREFIX . "onions WHERE $admin_approval category=? AND address!='' AND id NOT IN (SELECT onion_id FROM " . PREFIX . 'phishing) AND timediff<604800;' );
		foreach ( $categories as $cat => $name ) {
			$stmt->execute( [ $cat ] );
			$category_count[ $cat ] = $stmt->fetch( PDO::FETCH_NUM )[ 0 ];
			if ( $category == $cat ) {
				$pages = ceil( $category_count[ $cat ] / PER_PAGE );
			}
		}
		if ( $_REQUEST[ 'pg' ] > $pages && $_REQUEST[ 'pg' ] > 1 ) {
			http_response_code( 404 );
			$not_found = true;
		}
	}
	if($not_found) {
		$title = _( 'Onion link list - Not found');
	}elseif(!empty($_REQUEST['q'])) {
		$title = sprintf(_( 'Onion link list - Searching for %s' ), htmlspecialchars($_REQUEST['q']));
	} elseif ($category === count($categories) && $_REQUEST[ 'pg' ] > 1){
		$title = sprintf(_( 'Onion link list - Page %d' ), $_REQUEST[ 'pg' ]);
	} elseif ($category < count($categories) && $_REQUEST[ 'pg' ] > 1){
		$title = sprintf(_( 'Onion link list - %1$s - Page %2$d' ), $categories[$category], $_REQUEST[ 'pg' ]);
	} elseif ($category < count($categories)){
		$title = sprintf(_( 'Onion link list - %s' ), $categories[$category]);
	} elseif ($category === count($categories) + 3){
		$title = sprintf(_( 'Onion link list - %s' ), _('Phishing Clones'));
	} elseif ($category === count($categories) + 2 && $_REQUEST[ 'pg' ] > 1){
		$title = sprintf(_( 'Onion link list - %1$s - Page %2$d' ), _('Offline > 1 week'), $_REQUEST['pg']);
	} elseif ($category === count($categories) + 2){
		$title = sprintf(_( 'Onion link list - %s' ), _('Offline > 1 week'));
	} elseif ($category === count($categories) + 1){
		$title = sprintf(_( 'Onion link list - %s' ), _('Last added'));
	} else {
		$title = _( 'Onion link list' );
	}
	echo '<!DOCTYPE html><html lang="'.$language.'" dir="'.$dir.'"><head>';
	echo '<title>'.$title.'</title>';
	echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
	echo '<meta name="author" content="Daniel Winzen">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<meta name="description" content="'._('Huge link list of Tor hidden service onions. All the darknet links you need in one place.').'">';
	echo '<link rel="search" type="application/opensearchdescription+xml" href="/opensearch.php" title="'._('Onion link list').'"/>';
	echo '<link rel="canonical" href="' . CANONICAL_URL . (empty($canonical_query) ? '' : '?' . http_build_query($canonical_query)) . '">';
	echo '<link rel="alternate" href="' . CANONICAL_URL . (empty($canonical_query) ? '' : '?' . http_build_query($canonical_query)) . '" hreflang="x-default">';
	alt_links();
	echo '<meta property="og:type" content="website">';
	echo '<meta property="og:title" content="' . $title . '">';
	echo '<meta property="og:description" content="' . _('Huge link list of Tor hidden service onions. All the darknet links you need in one place.') . '">';
	echo '<meta property="og:url" content="' . CANONICAL_URL . (empty($canonical_query) ? '' : '?' . http_build_query($canonical_query)) . '">';
	echo '<meta property="og:locale" content="' . $locale . '">';
	if(empty($_SERVER['QUERY_STRING'])) {
		echo '<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebSite","url":"' . CANONICAL_URL . '","potentialAction":{"@type":"SearchAction","target":{"@type":"EntryPoint","urlTemplate":"' . CANONICAL_URL . '/?q={search_term_string}"},"query-input":"required name=search_term_string","url":"' . CANONICAL_URL . '"}}</script>';
	}
	echo '<style>'.$style.'</style>';
	echo '<base target="_blank">';
	echo '</head><body><main>';
	echo '<h1>'.$title.'</h1>';
	if(!isset($db)){
		send_error(_('Error: No database connection!'));
	}
	echo '<p>'._('I\'m not responsible for any content of websites linked here. 99% of darkweb sites selling anything are scams. Be careful and use your brain. I regularly receive E-Mails from people that were desperate to make money and fell for scammers, don\'t be one of them!').'</p>';
	//update onions description form
	echo '<div class="table" id="edit-search"><div class="row"><div class="col"><form target="_self" method="POST">';
	echo '<p><label>'._('Onion address:').'<br><input name="addr" size="30" placeholder="http://'.$_SERVER['HTTP_HOST'].'" value="';
	if(isset($_REQUEST['addr'])){
		echo htmlspecialchars($_REQUEST['addr']);
	}
	echo '" required></label></p>';
	echo '<p><label>'._('Description:').'<br><textarea name="desc" rows="2" cols="30">';
	if(!empty($_REQUEST['desc'])){//use posted description
		echo htmlspecialchars(trim($_REQUEST['desc']));
	}elseif(!empty($_REQUEST['addr'])){//fetch description from database
		if(preg_match('~(^(https?://)?([a-z0-9]*\.)?([a-z2-7]{55}d)(\.onion(/.*)?)?$)~i', trim($_REQUEST['addr']), $addr)){
			$addr=strtolower($addr[4]);
			$md5=md5($addr, true);
			$stmt=$db->prepare('SELECT description, category FROM ' . PREFIX . 'onions WHERE md5sum=?;');
			$stmt->execute([$md5]);
			if($desc=$stmt->fetch(PDO::FETCH_ASSOC)){
				echo str_replace('<br>', "\n", $desc['description']);
			}
		}
	}
	echo '</textarea></label></p>';
	echo '<p><label>'._('Category:').' <select name="cat">';
	foreach($categories as $cat=>$name){
		echo '<option value="'.$cat.'"';
		if($category==$cat || ($cat===0 && $category>=count($categories))){
			echo ' selected';
		}
		echo ">$name</option>";
	}
	echo '</select></label></p>';
	send_captcha();
	echo '<input type="submit" name="action" value="'._('Update').'"></form></div>';
	//search from
	echo '<div class="col"><form target="_self" method="post" role="search">';
	echo '<p><label>'._('Search:').' <br><input name="q" size="30" placeholder="'._('Search term').'" value="';
	if(isset($_REQUEST['q'])){
		echo trim(str_replace(['http://', 'https://', '.onion', '/'], '', htmlspecialchars($_REQUEST['q'])));
	}
	echo '"></label></p>';
	echo "<p><label>"._('Category:')." <select name=\"cat\">";
	echo '<option value="'.count($categories).'"';
	if($category>=count($categories)){
		echo ' selected';
	}
	echo '>'._('All').'</option>';
	foreach($categories as $cat=>$name){
		echo '<option value="'.$cat.'"';
		if($category==$cat){
			echo ' selected';
		}
		echo ">$name</option>";
	}
	echo '</select></label></p>';
	echo '<p><label><input type="checkbox" name="hidelocked" value="1"';
	if(isset($_REQUEST['hidelocked'])){
		echo ' checked';
	}
	echo '>'._('Hide locked').'</label></p>';
	echo '<input type="submit" name="action" value="'._('Search').'"></form></div>';
	echo '</div></div>';
	echo '<ul class="list"><li>'._('Format:').'</li><li><a href="?format=text" target="_self">Text</a></li><li><a href="?format=json" target="_self">JSON</a></li></ul>';
	print_langs();
	//List special categories
	echo '<ul class="list"><li>'._('Special categories:').'</li>';
	$cat=count($categories);
	foreach($special as $name=>$query){
		echo ' <li'.($category==$cat ? ' class="active"' : '').'><a href="'.($cat === count($categories) ? '/' : '?cat='.$cat).'" target="_self">'."$name ($category_count[$cat])</a></li>";
		++$cat;
	}
	echo ' <li'.($category==$cat ? ' class="active"' : '').'><a href="?cat='.$cat.'" target="_self">'._('Phishing Clones')." ($category_count[$cat])</a></li>";
	echo ' <li>'._('Removed/Child porn')." ($category_count[removed])</li>";
	if(REQUIRE_APPROVAL) {
		echo ' <li>'._('Pending approval')." ($category_count[pending])</li>";
		echo ' <li>'._('Rejected')." ($category_count[rejected])</li>";
	}
	echo '</ul>';
	//List normal categories
	echo '<ul class="list"><li>'._('Categories:').'</li>';
	foreach($categories as $cat=>$name){
		echo ' <li'.($category==$cat ? ' class="active"' : '').'><a href="?cat='.$cat.'" target="_self">'."$name ($category_count[$cat])</a></li>";
	}
	echo '</ul>';
	if($_SERVER['REQUEST_METHOD']==='POST' && !empty($_REQUEST['addr'])){
		if(!preg_match('~(^(https?://)?([a-z0-9]*\.)?([a-z2-7]{55}d)(\.onion(/.*)?)?$)~i', trim($_REQUEST['addr']), $addr)){
			echo '<p class="red" role="alert">'._('Invalid onion address!').'</p>';
			echo '<p>'.sprintf(_('A valid address looks like this: %s'), 'http://danielas3rtn54uwmofdo3x2bsdifr47huasnmbgqzfrec5ubupvtpid.onion') .'</p>';
		}else{
			if(CAPTCHA !== 0) {
				if ( ! isset( $_REQUEST[ 'challenge' ] ) ) {
					send_error( _( 'Error: Wrong captcha' ) );
				}
				$stmt = $db->prepare( 'SELECT code FROM ' . PREFIX . 'captcha WHERE id=?;' );
				$stmt->execute( [ $_REQUEST[ 'challenge' ] ] );
				$stmt->bindColumn( 1, $code );
				if ( ! $stmt->fetch( PDO::FETCH_BOUND ) ) {
					send_error( _( 'Error: Captcha expired' ) );
				}
				$time = time();
				$stmt = $db->prepare( 'DELETE FROM ' . PREFIX . 'captcha WHERE id=? OR time<?;' );
				$stmt->execute( [ $_REQUEST[ 'challenge' ], $time - 3600 ] );
				if ( $_REQUEST[ 'captcha' ] !== $code ) {
					if ( strrev( $_REQUEST[ 'captcha' ] ) !== $code ) {
						send_error( _( 'Error: Wrong captcha' ) );
					}
				}
			}
			$addr=strtolower($addr[4]);
			$md5=md5($addr, true);
			$stmt=$db->prepare('SELECT locked FROM ' . PREFIX . 'onions WHERE md5sum=?;');
			$stmt->execute([$md5]);
			$stmt->bindColumn(1, $locked);
			if($category==count($categories)){
				$category=0;
			}
			if(!isset($_POST['desc'])){
				$desc='';
			}else{
				$desc=trim($_POST['desc']);
				$desc=htmlspecialchars($desc);
				$desc=preg_replace("/(\r?\n|\r\n?)/", '<br>', $desc);
			}
			if(!$stmt->fetch(PDO::FETCH_BOUND)){//new link, add to database
				$stmt=$db->prepare('INSERT INTO ' . PREFIX . 'onions (address, description, md5sum, category, timeadded, timechanged) VALUES (?, ?, ?, ?, ?, ?);');
				$stmt->execute([$addr, $desc, $md5, $category, time(), time()]);
				echo '<p class="green" role="alert">'._('Successfully added onion address!').'</p>';
			}elseif($locked==1){//locked, not editable
				echo '<p class="red" role="alert">'._('Sorry, editing this onion address has been locked!').'</p>';
			}elseif($desc!==''){//update description
				$stmt=$db->prepare('UPDATE ' . PREFIX . 'onions SET description=?, category=?, timechanged=? WHERE md5sum=?;');
				$stmt->execute([$desc, $category, time(), $md5]);
				echo '<p class="green" role="alert">'._('Successfully updated description!').'</p>';
			}elseif($category!=0){//update category only
				$stmt=$db->prepare('UPDATE ' . PREFIX . 'onions SET category=?, timechanged=? WHERE md5sum=?;');
				$stmt->execute([$category, time(), $md5]);
				echo '<p class="green" role="alert">'._('Successfully updated category!').'</p>';
			}else{//nothing changed and already known
				echo '<p class="green" role="alert">'._('Thanks, but I already knew this address!').'</p>';
			}
		}
	}
	$pagination=get_pagination($category, $pages);
	echo $pagination;
	if(isset($_REQUEST['q'])){//run search query
		$query=trim(str_replace(['http://', 'https://', '.onion', '/'], '', htmlspecialchars($_REQUEST['q'])));
		$query="%$query%";
		if(isset($_REQUEST['hidelocked'])){
			$hidelocked='AND locked=0';
		}else{
			$hidelocked='';
		}
		if($category>=count($categories)){
			$stmt=$db->prepare('SELECT address, lasttest, lastup, timeadded, description, locked, special FROM ' . PREFIX . "onions WHERE $admin_approval address!='' AND id NOT IN (SELECT onion_id FROM " . PREFIX . "phishing) AND timediff<604800 $hidelocked AND (description LIKE ? OR address LIKE ?) ORDER BY address;");
			$stmt->execute([$query, $query]);
		}else{
			$stmt=$db->prepare('SELECT address, lasttest, lastup, timeadded, description, locked, special FROM ' . PREFIX . "onions WHERE $admin_approval address!='' AND category=? AND id NOT IN (SELECT onion_id FROM " . PREFIX . "phishing) AND timediff<604800 $hidelocked AND (description LIKE ? OR address LIKE ?) ORDER BY address;");
			$stmt->execute([$category, $query, $query]);
		}
		$table=get_table($stmt, $numrows);
		printf('<p><b>'._('Searching for "%1$s", %2$d results found:').'</b></p>', trim(str_replace(['http://', 'https://', '.onion', '/'], '', htmlspecialchars($_REQUEST['q']))), $numrows);
		echo $table;
	}elseif($category>=count($categories)+count($special)){//show phishing clones
		print_phishing_table();
	}elseif($category>=count($categories)){//show special categories
		$tmp=$category-count($categories);
		$query = '';
		foreach($special as $query){
			if($tmp===0) break;
			--$tmp;
		}
		if($category-count($categories)===1){
			$query.=' ORDER BY id DESC LIMIT ' . PER_PAGE;
		}else{
			$query.=' ORDER BY address';
			if($_REQUEST['pg']>0){
				$offset=PER_PAGE*($_REQUEST['pg']-1);
				$query.=' LIMIT ' . PER_PAGE ." OFFSET $offset";
			}
		}
		$stmt=$db->query('SELECT address, lasttest, lastup, timeadded, description, locked, special FROM ' . PREFIX . "onions WHERE $admin_approval $query;");
		echo get_table($stmt, $numrows, true);
	}else{//show normal categories
		if($_REQUEST['pg']>0){
			$offset=PER_PAGE*($_REQUEST['pg']-1);
			$offsetquery=' LIMIT ' . PER_PAGE . " OFFSET $offset";
		}else{
			$offsetquery='';
		}
		$stmt=$db->prepare('SELECT address, lasttest, lastup, timeadded, description, locked, special FROM ' . PREFIX . "onions WHERE $admin_approval address!='' AND id NOT IN (SELECT onion_id FROM " . PREFIX . "phishing) AND category=? AND timediff<604800 ORDER BY address$offsetquery;");
		$stmt->execute([$category]);
		echo get_table($stmt, $numrows, true);
	}
	echo '<br>';
	echo $pagination;
	echo '<br><p class="software-link"><a href="https://github.com/DanWin/onion-link-list" target="_blank" rel="noopener">Onion Link List - ' . VERSION . '</a></p>';
	echo '</main></body></html>';
}

function get_table(PDOStatement $stmt, int &$numrows = 0, bool $promoted = false) : string {
	global $db;
	$time=time();
	$admin_approval = '';
	if(REQUIRE_APPROVAL){
		$admin_approval = PREFIX . 'onions.approved = 1 AND';
	}
	ob_start();
	echo '<div class="table" id="maintable"><div class="row headerrow"><div class="col">'._('Onion link').'</div><div class="col">'._('Description').'</div><div class="col">'._('Last seen').'</div><div class="col">'._('Added at').'</div><div class="col">'._('Actions').'</div></div>';
	if($promoted){//print promoted links at the top
		$time=time();
		$promo=$db->prepare('SELECT address, lasttest, lastup, timeadded, description, locked, special FROM ' . PREFIX . "onions WHERE $admin_approval special>? AND address!='' AND id NOT IN (SELECT onion_id FROM " . PREFIX . 'phishing) AND timediff<604800 ORDER BY address;');
		$promo->execute([$time]);
		while($link=$promo->fetch(PDO::FETCH_ASSOC)){
			if($link['lastup']===$link['lasttest']){
				$class='up';
			}else{
				$class='down';
			}
			if($link['lastup']==0){
				$lastup=_('Never');
			}else{
				$lastup=date('Y-m-d H:i', $link['lastup']);
			}
			if($link['lasttest']==0){
				$class='';
			}
			$timeadded=date('Y-m-d', $link['timeadded']);
			echo '<div class="'.$class.' row promo"><div class="col"><a href="http://'.$link['address'].'.onion" rel="noopener">'.$link['address'].'.onion</a></div><div class="col">'.$link['description'].'</div><div class="col">'.$lastup.'</div><div class="col">'.$timeadded.'</div><div class="col"><form method="post" action="test.php"><button name="addr" value="'.$link['address'].'" type="submit">'._('Test').'</button></form></div></div>';
		}
	}
	while($link=$stmt->fetch(PDO::FETCH_ASSOC)){
		if($link['lastup']===$link['lasttest']){
			$class='up';
		}else{
			$class='down';
		}
		if($link['lastup']==0){
			$lastup=_('Never');
		}else{
			$lastup=date('Y-m-d H:i', $link['lastup']);
		}
		if($link['lasttest']==0){
			$class='';
		}
		$timeadded=date('Y-m-d', $link['timeadded']);
		if($link['special']>$time){
			$class.=' promo';
		}
		if($link['locked']==1){
			$edit='';
		}else{
			$edit='<form><button name="addr" value="'.$link['address'].'" type="submit">'._('Edit').'</button></form>';
		}
		echo '<div class="row '.$class.'"><div class="col"><a href="http://'.$link['address'].'.onion" rel="noopener">'.$link['address'].'.onion</a></div><div class="col">'.$link['description'].'</div><div class="col">'.$lastup.'</div><div class="col">'.$timeadded.'</div><div class="col">'.$edit.' <form method="post" action="test.php"><button name="addr" value="'.$link['address'].'" type="submit">'._('Test').'</button></form></div></div>';
		++$numrows;
	}
	echo '</div>';
	return ob_get_clean();
}

function print_phishing_table(): void
{
	global $db;
	$admin_approval = '';
	if(REQUIRE_APPROVAL){
		$admin_approval = 'approved = 1 AND';
	}
	echo '<div class="table" id="maintable"><div class="row headerrow"><div class="col">'._('Onion link').'</div><div class="col">'._('Clone of').'</div><div class="col">'._('Last seen').'</div></div>';
	$stmt=$db->query('SELECT address, original, lasttest, lastup FROM ' . PREFIX . 'onions, ' . PREFIX . 'phishing WHERE ' . "$admin_approval " . PREFIX . "onions.id=onion_id AND address!='' AND timediff<604800 ORDER BY address;");
	while($link=$stmt->fetch(PDO::FETCH_ASSOC)){
		if($link['lastup']===$link['lasttest']){
			$class='up';
		}else{
			$class='down';
		}
		if($link['lastup']==0){
			$lastup=_('Never');
		}else{
			$lastup=date('Y-m-d H:i', $link['lastup']);
		}
		if($link['original']!==''){
			$orig='<a href="http://'.$link['original'].'.onion" rel="noopener">'.$link['original'].'.onion</a>';
		}else{
			$orig=_('Unknown');
		}
		echo '<div class="row '.$class.'"><div class="col">'.$link['address'].'.onion</div><div class="col">'.$orig.'</div><div class="col">'.$lastup.'</div></div>';
	}
	echo '</div>';
}

function send_text(): void
{
	global $db;
	if(!isset($db)){
		die(_('Error: No database connection!'));
	}
	header('Content-Type: text/plain; charset=UTF-8');
	$admin_approval = '';
	if(REQUIRE_APPROVAL){
		$admin_approval = 'approved = 1 AND';
	}
	$stmt=$db->query('SELECT address FROM ' . PREFIX . "onions WHERE $admin_approval address!='' AND id NOT IN (SELECT onion_id FROM " . PREFIX . 'phishing) AND timediff<604800 ORDER BY address;');
	while($tmp=$stmt->fetch(PDO::FETCH_NUM)){
		echo "$tmp[0].onion\n";
	}
}

function send_json(): void
{
	global $db, $categories;
	if(!isset($db)){
		die(_('Error: No database connection!'));
	}
	header('Content-Type: application/json;');
	$admin_approval = '';
	if(REQUIRE_APPROVAL){
		$admin_approval = PREFIX . 'onions.approved = 1 AND';
	}
	$data=['categories'=>$categories];
	$stmt=$db->query('SELECT address, category, description, locked, lastup, lasttest, timeadded, timechanged FROM ' . PREFIX . "onions WHERE $admin_approval address!='' AND id NOT IN (SELECT onion_id FROM " . PREFIX . 'phishing) AND timediff<604800 ORDER BY address;');
	$data['onions']=$stmt->fetchALL(PDO::FETCH_ASSOC);
	$stmt=$db->query('SELECT md5sum FROM ' . PREFIX . "onions WHERE address='';");
	while($tmp=$stmt->fetch(PDO::FETCH_ASSOC)){
		$data['removed'][]=bin2hex($tmp['md5sum']);
	}
	$stmt=$db->query('SELECT address, original FROM ' . PREFIX . 'onions, ' . PREFIX . 'phishing WHERE onion_id=' . PREFIX . "onions.id AND $admin_approval address!='' AND timediff<604800 ORDER BY address;");
	$data['phishing']=$stmt->fetchALL(PDO::FETCH_ASSOC);
	echo json_encode($data);
}

function get_pagination(int $category, int $pages) : string {
	$pagination = '';
	if($pages<=1 || isset($_REQUEST['q'])){
		return $pagination;
	}
	$pagination .= '<ul class="list pagination"><li>'._('Pages:').'</li>';
	$pagination .= ' <li'.($_REQUEST['pg']===0 ? ' class="active"' : '').'><a href="?cat='.$category.'&amp;pg=0" target="_self">'._('All').'</a></li>';
	$pagination .= ' <li'.($_REQUEST['pg']===1 ? ' class="active"' : '').'><a href="?cat='.$category.'" target="_self">1</a></li>';
	$i = $_REQUEST['pg'] - 5;
	if($i < 2 ){
		$i = 2;
	} else {
		$pagination .= '<li>…</li>';
	}
	$j = 0;
	for(; $i<=$pages && ++$j < 12; ++$i){
		$pagination .= ' <li'.($_REQUEST['pg']===$i ? ' class="active"' : '').'><a href="?cat='.$category.'&amp;pg='.$i.'" target="_self">'.$i.'</a></li>';
	}
	if($i<=$pages){
		if($i<$pages){
			$pagination .= '<li>…</li>';
		}
		$pagination .= ' <li><a href="?cat='.$category.'&amp;pg='.$pages.'" target="_self">'.$pages.'</a></li>';
	}
	$pagination .= '</ul>';
	return $pagination;
}

function send_captcha(): void
{
	global $db;
	if(CAPTCHA === 0 || !extension_loaded('gd')){
		return;
	}
	$captchachars='ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
	$length=strlen($captchachars)-1;
	$code='';
	for($i=0;$i<5;++$i){
		$code.=$captchachars[mt_rand(0, $length)];
	}
	$randid=mt_rand();
	$time=time();
	$stmt=$db->prepare('INSERT INTO ' . PREFIX . 'captcha (id, time, code) VALUES (?, ?, ?);');
	$stmt->execute([$randid, $time, $code]);
	echo '<p><label>'._('Copy:');
	if(CAPTCHA === 1){
		$im = imagecreatetruecolor(55, 24);
		$bg = imagecolorallocate($im, 0, 0, 0);
		$fg = imagecolorallocate($im, 255, 255, 255);
		imagefill($im, 0, 0, $bg);
		imagestring($im, 5, 5, 5, $code, $fg);
		echo ' <img width="55" height="24" alt="'._('captcha image').'" src="data:image/gif;base64,';
	}elseif(CAPTCHA === 2){
		$im = imagecreatetruecolor(55, 24);
		$bg = imagecolorallocate($im, 0, 0, 0);
		$fg = imagecolorallocate($im, 255, 255, 255);
		imagefill($im, 0, 0, $bg);
		imagestring($im, 5, 5, 5, $code, $fg);
		$line = imagecolorallocate($im, 255, 255, 255);
		for($i = 0; $i < 2; ++$i){
			imageline($im, 0, mt_rand(0, 24), 55, mt_rand(0, 24), $line);
		}
		$dots = imagecolorallocate($im, 255, 255, 255);
		for($i = 0; $i < 100; ++$i){
			imagesetpixel($im, mt_rand(0, 55), mt_rand(0, 24), $dots);
		}
		echo ' <img width="55" height="24" alt="'._('captcha image').'" src="data:image/gif;base64,';
	}else{
		$im = imagecreatetruecolor(150, 200);
		$bg = imagecolorallocate($im, 0, 0, 0);
		$fg = imagecolorallocate($im, 255, 255, 255);
		imagefill($im, 0, 0, $bg);
		$line = imagecolorallocate($im, 100, 100, 100);
		for($i = 0; $i < 5; ++$i){
			imageline($im, 0, mt_rand(0, 200), 150, mt_rand(0, 200), $line);
		}
		$dots = imagecolorallocate($im, 200, 200, 200);
		for($i = 0; $i < 1000; ++$i){
			imagesetpixel($im, mt_rand(0, 150), mt_rand(0, 200), $dots);
		}
		$chars = [];
		for($i = 0; $i < 10; ++$i){
			$found = false;
			while(!$found){
				$x = mt_rand(10, 140);
				$y = mt_rand(10, 180);
				$found = true;
				foreach($chars as $char){
					if($char['x'] >= $x && ($char['x'] - $x) < 25){
						$found = false;
					}elseif($char['x'] < $x && ($x - $char['x']) < 25){
						$found = false;
					}
					if(!$found){
						if($char['y'] >= $y && ($char['y'] - $y) < 25){
							break;
						}elseif($char['y'] < $y && ($y - $char['y']) < 25){
							break;
						}else{
							$found = true;
						}
					}
				}
			}
			$chars []= ['x', 'y'];
			$chars[$i]['x'] = $x;
			$chars[$i]['y'] = $y;
			if($i < 5){
				imagechar($im, 5, $chars[$i]['x'], $chars[$i]['y'], $captchachars[mt_rand(0, $length)], $fg);
			}else{
				imagechar($im, 5, $chars[$i]['x'], $chars[$i]['y'], $code[$i-5], $fg);
			}
		}
		$follow=imagecolorallocate($im, 200, 0, 0);
		imagearc($im, $chars[5]['x']+4, $chars[5]['y']+8, 16, 16, 0, 360, $follow);
		for($i = 5; $i < 9; ++$i){
			imageline($im, $chars[$i]['x']+4, $chars[$i]['y']+8, $chars[$i+1]['x']+4, $chars[$i+1]['y']+8, $follow);
		}
		echo ' <img width="150" height="200" alt="'._('captcha image').'" src="data:image/gif;base64,';
	}
	ob_start();
	imagegif($im);
	imagedestroy($im);
	echo base64_encode(ob_get_clean()).'">';
	echo '<input type="hidden" name="challenge" value="'.$randid.'"><input type="text" name="captcha" size="15" autocomplete="off"></label></p>';
}

function send_error(string $msg): void
{
	die('<p class="red" role="alert">'.$msg.'</p></main></body></html>');
}
