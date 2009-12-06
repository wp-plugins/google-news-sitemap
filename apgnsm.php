<?php
	
/*
	Plugin Name: Google News Sitemap
	Plugin URI: http://andreapernici.com/wordpress/google-news-sitemap/
	Description: Automatically generate sitemap for inclusion in Google News. Go to <a href="options-general.php?page=apgnsm.php">Settings -> Google News Sitemap</a> for setup.
	Version: 1.0.5
	Author: Andrea Pernici
	Author URI: http://www.andreapernici.com/
	
	Copyright 2009 Andrea Pernici (andreapernici@gmail.com)
	
	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
	
	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

	*/

	$apgnsm_sitemap_version = "1.0.5";

	// Aggiungiamo le opzioni di default
	add_option('apgnsm_news_active', true);
	add_option('apgnsm_tags', true);
	add_option('apgnsm_path', "./");
	add_option('apgnsm_last_ping', 0);
	//add_option('apgnsm_publication_name','<publication_name>');
	add_option('apgnsm_n_name','Your Google News Name');
	add_option('apgnsm_n_lang','it');
	// Genere dei contenuti
	add_option('apgnsm_n_genres',false);
	add_option('apgnsm_n_genres_type','blog');
	// Tipo di accesso dell'articolo - Facoltativo
	add_option('apgnsm_n_access',false);
	add_option('apgnsm_n_access_type','Subscription');
	//add_option('apgnsm_n_access_type','Registration');
	//add_option('apgnsm_n_excludecat',false);
	add_option('apgnsm_n_excludecatlist','');
	add_option('apgnsm_n_excludepostlist','');
	//Controllo eliminazione, pubblicazione pagine post per rebuild
	add_action('delete_post', apgnsm_autobuild ,9999,1);	
	add_action('publish_post', apgnsm_autobuild ,9999,1);	
	add_action('publish_page', apgnsm_autobuild ,9999,1);

	// Carichiamo le opzioni
	$apgnsm_news_active = get_option('apgnsm_news_active');
	$apgnsm_path = get_option('apgnsm_path');
	//$apgnsm_publication_name = get_option('apgnsm_publication_name','<publication_name>');
	$apgnsm_n_name = get_option('apgnsm_n_name','<n:name>');
	$apgnsm_n_lang = get_option('apgnsm_n_lang','<n:language>');
	$apgnsm_n_access = get_option('apgnsm_n_access','<n:access>');
	$apgnsm_n_genres = get_option('apgnsm_n_genres','<n:genres>');
	
	
	// Aggiungiamo la pagina delle opzioni
	add_action('admin_menu', 'apgnsm_add_pages');
	
	//Aggiungo la pagina della configurazione
	function apgnsm_add_pages() {
		add_options_page("Google News Sitemap", "Sitemap Google News", 8, basename(__FILE__), "apgnsm_admin_page");
	}

	
	function apgnsm_escapexml($string) {
		return str_replace ( array ( '&', '"', "'", '<', '>'), array ( '&amp;' , '&quot;', '&apos;' , '&lt;' , '&gt;'), $string);
	}
	
	function apgnsm_permissions() {

		$apgnsm_news_active = get_option('apgnsm_news_active');
		
		$apgnsm_path = ABSPATH . get_option('apgnsm_path');
		$apgnsm_news_file_path = $apgnsm_path . "sitemap-news.xml";
		
		
		if ($apgnsm_news_active && is_file($apgnsm_news_file_path) && is_writable($apgnsm_news_file_path)) $apgnsm_permission += 0;
		elseif ($apgnsm_news_active && !is_file($apgnsm_news_file_path) && is_writable($apgnsm_path)) {
			$fp = fopen($apgnsm_news_file_path, 'w');
			fwrite($fp, "<?xml version=\"1.0\" encoding=\"UTF-8\"?><urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:n=\"http://www.google.com/schemas/sitemap-news/0.9\" />");
			fclose($fp);
			if (is_file($apgnsm_news_file_path) && is_writable($apgnsm_news_file_path)) $apgnsm_permission += 0;
			else $apgnsm_permission += 2;
		}
		elseif ($apgnsm_news_active) $apgnsm_permission += 2;
		else $apgnsm_permission += 0;

		return $apgnsm_permission;
	}

	/*
		Auto Build sitemap
	*/
	function apgnsm_autobuild($postID) {
		global $wp_version;
		$isScheduled = false;
		$lastPostID = 0;
		//Ricostruisce la sitemap una volta per post se non fa import
		if($lastPostID != $postID && (!defined('WP_IMPORTING') || WP_IMPORTING != true)) {
			
			//Costruisce la sitemap direttamente oppure fa un cron
			if(floatval($wp_version) >= 2.1) {
				if(!$isScheduled) {
					//Ogni 15 secondi.
					//Pulisce tutti gli hooks.
					wp_clear_scheduled_hook(apgnsm_generate_sitemap());
					wp_schedule_single_event(time()+15,apgnsm_generate_sitemap());
					$isScheduled = true;
				}
			} else {
				//Costruisce la sitemap una volta sola e mai in bulk mode
				if(!$lastPostID && (!isset($_GET["delete"]) || count((array) $_GET['delete'])<=0)) {
					apgnsm_generate_sitemap();
				}
			}
			$lastPostID = $postID;
		}
	}
	
	
	function apgnsm_generate_sitemap() {
		global $apgnsm_sitemap_version, $table_prefix;
		global $wpdb;
		
		$t = $table_prefix;
		
		$apgnsm_news_active = get_option('apgnsm_news_active');
		$apgnsm_path = get_option('apgnsm_path');
		//add_option('apgnsm_publication_name','<publication_name>');
		$apgnsm_n_name = get_option('apgnsm_n_name');
		$apgnsm_n_lang = get_option('apgnsm_n_lang');
		// Genere dei contenuti
		$apgnsm_n_genres = get_option('apgnsm_n_genres');
		$apgnsm_n_genres_type = get_option('apgnsm_n_genres_type');
		// Tipo di accesso dell'articolo - Facoltativo
		$apgnsm_n_access = get_option('apgnsm_n_access');
		$apgnsm_n_access_type = get_option('apgnsm_n_access_type');
		//add_option('apgnsm_n_access_type','Registration');
		//$apgnsm_n_excludecat = get_option('apgnsm_n_excludecat');
		$apgnsm_n_excludecatlist = get_option('apgnsm_n_excludecatlist');
		$apgnsm_n_excludepostlist = get_option('apgnsm_n_excludepostlist');
		
		$includeMe = '';
		$includeNoPost = '';
		if ( $apgnsm_n_excludecatlist <> NULL ) {
			$exPosts = get_objects_in_term($apgnsm_n_excludecatlist,"category");
			$includeNoCat = ' AND `ID` NOT IN ('.implode(",",$exPosts).')';
			}
		if ($apgnsm_n_excludepostlist != ''){
			$includeNoPost = ' AND `ID` NOT IN ('.$apgnsm_n_excludepostlist.')';
			}
		
		$apgnsm_permission = apgnsm_permissions();
		if ($apgnsm_permission > 2 || (!$apgnsm_active && !$apgnsm_news_active)) return;

		//mysql_connect(DB_HOST, DB_USER, DB_PASSWORD);
		//mysql_query("SET NAMES '".DB_CHARSET."'");
		//mysql_select_db(DB_NAME);

		$home = get_option('home') . "/";

		$xml_sitemap_google_news = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>";
		$xml_sitemap_google_news .= "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:n=\"http://www.google.com/schemas/sitemap-news/0.9\">
	<!-- Generated by Google News Sitemap ".$apgnsm_sitemap_version." -->
	<!-- plugin by Andrea Pernici -->
	<!-- http://andreapernici.com/wordpress/google-news-sitemap/ -->
	<!-- Created ".date("F d, Y, H:i")." -->";

		$posts = $wpdb->get_results("SELECT * FROM ".$wpdb->posts." WHERE `post_status`='publish' 
		AND (`post_type`='page' OR `post_type`='post') ". $includeNoCat . ' ' . $includeNoPost." GROUP BY `ID` ORDER BY `post_modified_gmt` DESC");		
		
		$now = time();
		$twoDays = 2*24*60*60;
		
		foreach ($posts as $post) {
			if ($apgnsm_news_active && $apgnsm_permission != 2) {
				$postDate = strtotime($post->post_date);
				if ($now - $postDate < $twoDays) {
					$xml_sitemap_google_news .= "
	<url>
		<loc>".apgnsm_escapexml(get_permalink($post->ID))."</loc>
		<n:news>
			<n:publication>
				<n:name>".$apgnsm_n_name."</n:name>
				<n:language>".$apgnsm_n_lang."</n:language>
			</n:publication>";
					
					// Se selzionato il genere allora lo aggiungo
					if ($apgnsm_n_genres == true) {
						$xml_sitemap_google_news .= "
						<n:genres>".$apgnsm_n_genres_type."</n:genres>";
						}
					// Se selzionato il tipo di accesso allora lo aggiungo
					if ($apgnsm_n_access == true) {
						$xml_sitemap_google_news .= "
						<n:access>".$apgnsm_n_access_type."</n:access>";
						}	
						
					$xml_sitemap_google_news .= "	
			<n:publication_date>".str_replace(" ", "T", $post->post_date_gmt)."Z"."</n:publication_date>
			<n:title>".htmlspecialchars($post->post_title)."</n:title>
		</n:news>
	</url>";
				}
			}
		}

		$xml_sitemap_google_news .= "\n</urlset>";
		
		
		if ($apgnsm_news_active && $apgnsm_permission != 2) {
			$fp = fopen(ABSPATH . $apgnsm_path . "sitemap-news.xml", 'w');
			fwrite($fp, $xml_sitemap_google_news);
			fclose($fp);
		}
		

		$apgnsm_last_ping = get_option('apgnsm_last_ping');
		if ((time() - $apgnsm_last_ping) > 60 * 60) {
			//get_headers("http://www.google.com/webmasters/tools/ping?sitemap=" . urlencode($home . $apgnsm_path . "sitemap.xml"));	//PHP5+
			$fp = @fopen("http://www.google.com/webmasters/tools/ping?sitemap=" . urlencode($home . $apgnsm_path . "sitemap-news.xml"), 80);
			@fclose($fp);
			update_option('apgnsm_last_ping', time());
		}
	}



	//Config page
	function apgnsm_admin_page() {
		$msg = "";

		// Check form submission and update options
		if ('apgnsm_submit' == $_POST['apgnsm_submit']) {
			update_option('apgnsm_news_active', $_POST['apgnsm_news_active']);
			update_option('apgnsm_n_name', $_POST['apgnsm_n_name']);
			update_option('apgnsm_n_lang', $_POST['apgnsm_n_lang']);
			update_option('apgnsm_n_access', $_POST['apgnsm_n_access']);
			update_option('apgnsm_n_genres', $_POST['apgnsm_n_genres']);
			update_option('apgnsm_n_excludecat', $_POST['apgnsm_n_excludecat']);
			update_option('apgnsm_n_excludepostlist', $_POST['apgnsm_n_excludepostlist']);
			
			$newPath = trim($_POST['apgnsm_path']);
			if ($newPath == "" || $newPath == "/") $newPath = "./";
			elseif ($newPath[strlen($newPath)-1] != "/") $newPath .= "/";
			
			update_option('apgnsm_path', $newPath);
			
			if ( $_POST['apgnsm_n_genres_type']=="Blog" 
				 || $_POST['apgnsm_n_genres_type']=="PressReleases"
			     || $_POST['apgnsm_n_genres_type']=="UserGenerated" 
			     || $_POST['apgnsm_n_genres_type']=="Satire" 
				 || $_POST['apgnsm_n_genres_type']=="OpEd" 
				 || $_POST['apgnsm_n_genres_type']=="Opinion" ) {
				update_option('apgnsm_n_genres_type', $_POST['apgnsm_n_genres_type']);
			} else { 
				update_option('apgnsm_n_genres_type', "blog"); 
			}
			
			if ($_POST['$apgnsm_n_access_type']=="Subscription" || $_POST['apgnsm_n_access_type']=="Registration" ) update_option('apgnsm_n_access_type', $_POST['apgnsm_n_access_type']);
			else update_option('apgnsm_n_access_type', "Subscription");
			
			// Excluded category
			$exCats = array();
			if(isset($_POST["post_category"])) {
				foreach((array) $_POST["post_category"] AS $vv) if(!empty($vv) && is_numeric($vv)) $exCats[] = intval($vv);
			}
			update_option('apgnsm_n_excludecatlist', $exCats); 
			
			// Sitemap generation
			apgnsm_generate_sitemap();
		}
		
		$apgnsm_news_active = get_option('apgnsm_news_active');
		$apgnsm_path = get_option('apgnsm_path');
		$apgnsm_n_name = get_option('apgnsm_n_name');
		$apgnsm_n_lang = get_option('apgnsm_n_lang');
		$apgnsm_n_genres = get_option('apgnsm_n_genres');
		$apgnsm_n_genres_type = get_option('apgnsm_n_genres_type');
		$apgnsm_n_access = get_option('apgnsm_n_access');
		$apgnsm_n_access_type = get_option('apgnsm_n_access_type');
		$apgnsm_n_excludepostlist = get_option('apgnsm_n_excludepostlist');
		
		$apgnsm_permission = apgnsm_permissions();
		
		if ($apgnsm_permission == 1) $msg = "Error: there is a problem with <em>sitemap-news.xml</em>. It doesn't exist or is not writable. <a href=\"http://www.andreapernici.com/wordpress/google-news-sitemap/\" target=\"_blank\" >For help see the plugin's homepage</a>.";
		elseif ($apgnsm_permission == 2) $msg = "Error: there is a problem with <em>sitemap-news.xml</em>. It doesn't exist or is not writable. <a href=\"http://www.andreapernici.com/wordpress/google-news-sitemap/\" target=\"_blank\" >For help see the plugin's homepage</a>.";
		elseif ($apgnsm_permission == 3) $msg = "Error: there is a problem with <em>sitemap-news.xml</em>. It doesn't exist or is not writable. <a href=\"http://www.andreapernici.com/wordpress/google-news-sitemap/\" target=\"_blank\" >For help see the plugin's homepage</a>.";
?>

<style type="text/css">
a.sm_button {
			padding:4px;
			display:block;
			padding-left:25px;
			background-repeat:no-repeat;
			background-position:5px 50%;
			text-decoration:none;
			border:none;
		}
		 
.sm-padded .inside {
	margin:12px!important;
}
.sm-padded .inside ul {
	margin:6px 0 12px 0;
}

.sm-padded .inside input {
	padding:1px;
	margin:0;
}
</style> 
            

 
<div class="wrap" id="sm_div">
    <h2>Google News Sitemap</h2> 
    by <strong>Andrea Pernici</strong>
    <p>
    &nbsp;<a target="_blank" title="Google News Sitemap Plugin Release History" href="http://andreapernici.com/wordpress/google-news-sitemap/">Changelog</a> 
    | <a target="_blank" title="Google News Sitemap Support" href="http://andreapernici.com/wordpress/google-news-sitemap/">Support</a>
	</p>
<?php	if ($msg) {	?>
	<div id="message" class="error"><p><strong><?php echo $msg; ?></strong></p></div>
<?php	}	?>

    <div style="width:824px;"> 
        <div style="float:left;background-color:white;padding: 10px 10px 10px 10px;margin-right:15px;border: 1px solid #ddd;"> 
            <div style="width:350px;height:130px;"> 
            <h3>Donate</h3> 
            <em>If you like this plugin and find it useful, help keep this plugin free and actively developed by going to the <a href="http://andreapernici.com/donazioni" target="_blank"><strong>donate</strong></a> page on my website.</em> 
            <p><em>Also, don't forget to follow me on <a href="http://twitter.com/andreapernici/" target="_blank"><strong>Twitter</strong></a>.</em></p> 
            </div> 
        </div> 
         
        <div style="float:left;background-color:white;padding: 10px 10px 10px 10px;border: 1px solid #ddd;"> 
            <div style="width:415px;height:130px;"> 
                <h3>Google Guidelines and Credits</h3> 
                <p><em>For any doubt refer to google guidelines <a href="http://www.google.com/support/webmasters/bin/answer.py?hl=en&answer=74288">here</a>.</em></p>
        <p><em>Plugin by <a href="http://www.andreapernici.com">Andrea Pernici</a> with support of <a href="http://www.ghenghe.com/">Ghenghe Social Bookmark</a> and <a href="http://www.ciakprestitiemutui.com/">Ciak Prestiti e Mutui</a>. We would also like to recommend <a href="http://www.convegnogt.it/">Convegno Gt</a> to discover new important tricks for Google News Ranking.</em> </p>
            </div> 
        </div> 
    </div>
    <div style="clear:both";></div> 
</div>



<div id="wpbody-content"> 

<div class="wrap" id="sm_div">

<div id="poststuff" class="metabox-holder has-right-sidebar"> 
    <div class="inner-sidebar"> 
		<div id="side-sortables" class="meta-box-sortabless ui-sortable" style="position:relative;"> 
			<div id="sm_pnres" class="postbox"> 
				<h3 class="hndle"><span>Info plugin:</span></h3> 
				<div class="inside"> 
                    <a class="sm_button sm_pluginHome"    href="http://www.andreapernici.com/wordpress/google-news-sitemap/">Plugin Homepage</a> 
                    <a class="sm_button sm_pluginList"    href="http://www.google.com/support/webmasters/bin/answer.py?hl=en&answer=74288">Google News Guidelines</a> 
                    <a class="sm_button sm_pluginSupport" href="http://wordpress.org/extend/plugins/google-news-sitemap/">Forum</a>
                    <a class="sm_button sm_donatePayPal"  href="http://andreapernici.com/donazioni">Donations</a>
                </div> 
			</div>
        </div>
    </div>




<div class="has-sidebar sm-padded" > 
					
<div id="post-body-content" class="has-sidebar-content"> 

<div class="meta-box-sortabless"> 
                                
<div id="sm_rebuild" class="postbox"> 
	<h3 class="hndle"><span>Google News Sitemap settings</span></h3>
    <div class="inside"> 

		<form name="form1" method="post" action="<?php echo $_SERVER["REQUEST_URI"]; ?>&amp;updated=true">
			<input type="hidden" name="apgnsm_submit" value="apgnsm_submit" />
            <ul>
                <li>
                <label for="apgnsm_news_active">
                    <input name="apgnsm_news_active" type="checkbox" id="apgnsm_news_active" value="1" <?php echo $apgnsm_news_active?'checked="checked"':''; ?> />
                    Create news sitemap.
                </label>
                </li>
                <li><label for="apgnsm_n_name">Your Google News Name: <input name="apgnsm_n_name" type="text" id="apgnsm_n_name" value="<?php echo $apgnsm_n_name?>" /></label></li>
				<li><label for="apgnsm_n_lang">Your Article Language (it, en, es...): <input name="apgnsm_n_lang" type="text" id="apgnsm_n_lang" value="<?php echo $apgnsm_n_lang?>" /></label></li>
				<li>
                <label for="apgnsm_n_genres">
					<input name="apgnsm_n_genres" type="checkbox" id="apgnsm_n_genres" value="1" <?php echo $apgnsm_n_genres?'checked="checked"':''; ?> />
					Show GENRES, if possible.
				</label>
                </li>
                <li>
                <label for="apgnsm_n_genres_type">If GENRES is defined then select the type of it: 
						<select name="apgnsm_n_genres_type">
							<option <?php echo $apgnsm_n_genres_type=="Blog"?'selected="selected"':'';?> value="Blog">Blog</option>
							<option <?php echo $apgnsm_n_genres_type=="PressReleases"?'selected="selected"':'';?> value="PressReleases">PressReleases</option>
							<option <?php echo $apgnsm_n_genres_type=="UserGenerated"?'selected="selected"':'';?> value="UserGenerated">UserGenerated</option>
                            <option <?php echo $apgnsm_n_genres_type=="Satire"?'selected="selected"':'';?> value="Satire">Satire</option>
                            <option <?php echo $apgnsm_n_genres_type=="OpEd"?'selected="selected"':'';?> value="OpEd">OpEd</option>
                            <option <?php echo $apgnsm_n_genres_type=="Opinion"?'selected="selected"':'';?> value="Opinion">Opinion</option>
                        </select>
                </label>
                </li>
                <li>
                <label for="apgnsm_n_access">
					<input name="apgnsm_n_access" type="checkbox" id="apgnsm_n_access" value="1" <?php echo $apgnsm_n_access?'checked="checked"':''; ?> />
					Enable limited access "Subscription" or "Registration".
				</label>
                </li>
                <li>
                <label for="apgnsm_n_access_type">
                If ACCESS is defined then select the type of it: 
					<select name="apgnsm_n_access_type">
						<option <?php echo $apgnsm_n_access_type=="Subscription"?'selected="selected"':'';?> value="Subscription">Subscription</option>
						<option <?php echo $apgnsm_n_access_type=="Registration"?'selected="selected"':'';?> value="Registration">Registration</option>		
					</select>
                </label>
                </li>
                </ul>
                <b>Advanced settings - default sitemap will be generated in http://yourdomain.com/sitemap-news.xml</b>
                <ul>
                <li>
                <label for="apgnsm_path">
                Sitemap path (relatively to blog's home) (leave empty if not sure): <input name="apgnsm_path" type="text" id="apgnsm_path" value="<?php echo $apgnsm_path?>" />
                </label>
                </li>
				</ul>	
                
           </div>
           </div>
                <!-- Excluded Items --> 
                
				<div id="sm_excludes" class="postbox"> 
				<h3 class="hndle"><span>Escludi elementi</span></h3> 
				
                <div class="inside"> 
								
				<b>Exclude Category:</b> 

<?php 
$excludedCats = get_option('apgnsm_n_excludecatlist');
if (!is_array($excludedCats)) $excludedCats = array();
?>
<div style="border-color:#CEE1EF; border-style:solid; border-width:2px; height:10em; margin:5px 0px 5px 40px; overflow:auto; padding:0.5em 0.5em;"> 
<ul> 
 <?php wp_category_checklist(0,0,$excludedCats,false); ?> 

</ul> 

</div> 
												
						<b>Exlclude Articles:</b> 
						<div style="margin:5px 0 13px 40px;"> 
							<label for="apgnsm_n_excludepost">Exclude the following articles or pages: <small>put comma separated ID (ex. 1,2,3)</small><br /> 
							<input name="apgnsm_n_excludepostlist" id="apgnsm_n_excludepostlist" type="text" style="width:400px;" value="<?php echo $apgnsm_n_excludepostlist;?>" /></label><br /> 
						</div> 
						
									</div> 
			</div> 
								<!-- Excluded -->
			<p class="submit"> <input type="submit" value="Save &amp; Rebuild" /></p>
		</form>
        
        
    </div>
    </div>
    </div>
</div>
</div> 
</div>
<?php
	}
?>
