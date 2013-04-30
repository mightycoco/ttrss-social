<?php
class Social extends Plugin {
	private $dbh;
	private $host;
	private $dummy_id;

	function about() { return array(1.0, "Adds support commenting and sharing", "mightycoco", false); }

	function get_css() { return file_get_contents(dirname(__FILE__) . "/social.css"); }

	function api_version() { return 2; }

   	function init($host) {
		$this->dbh = $host->get_dbh();
		$this->host = $host;
		$user = $_SESSION["name"];

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$this->dummy_id = $host->add_feed(-1, "{$user}'s Social", 'images/pub_set.svg', $this);
	}

	function get_unread($feed_id) {
		return 23;
	}

	function get_headlines($feed_id, $options) {
		$owner_uid = $_SESSION["uid"];
		$user = $_SESSION["name"];

		if (get_pref("SORT_HEADLINES_BY_FEED_DATE", $owner_uid)) {
			$date_sort_field = "updated";
		} else {
			$date_sort_field = "date_entered";
		}

		$query = "SELECT DISTINCT
						date_entered,
						guid,
						ttrss_entries.id,ttrss_entries.title,
						updated,
						label_cache,
						tag_cache,
						always_display_enclosures,
						site_url,
						note,
						num_comments,
						comments,
						int_id,
						hide_images,
						unread,feed_id,marked,published,link,last_read,orig_feed_id,
						last_marked,last_published,
						ttrss_feeds.title AS feed_title,
						content as content_preview, cached_content, 
						author,score
					FROM
						ttrss_entries,ttrss_user_entries,ttrss_feeds,ttrss_labels2,ttrss_user_labels2,
						ttrss_social_comments,ttrss_social_friends
					WHERE
						ttrss_user_entries.feed_id = ttrss_feeds.id AND
						ttrss_user_entries.ref_id = ttrss_entries.id AND
						ttrss_social_comments.entry_id = ttrss_entries.id AND
						(
							ttrss_social_friends.user_id = $owner_uid OR
							ttrss_social_friends.friend_id = $owner_uid
						) AND
						ttrss_social_friends.status = 'accepted'
					ORDER BY $date_sort_field DESC, updated DESC
					LIMIT 0, 30";

		//$query = "SELECT * FROM ttrss_entries LIMIT 0,10";
		$result = $this->dbh->query($query);

		return array($result, 
				"{$user}'s Social", 
				"", // url
				"" // last error
				);

		$qfh_ret = queryFeedHeadlines(-4,
			$options['limit'],
			$options['view_mode'], $options['cat_view'],
			$options['search'],
			$options['search_mode'],
			$options['override_order'],
			$options['offset'],
			$options['owner_uid'],
			$options['filter'],
			$options['since_id'],
			$options['include_children']);

		var_dump($qfh_ret);
		die();
		$qfh_ret[1] = 'Dummy feed';

		return $qfh_ret;
	}

	function requestUser() {
		$user = $this->dbh->db_escape_string($_POST["user"]);
		$uid = $_SESSION["uid"];

		//$this->host->set($this, "social", $user);
		$result = $this->dbh->query("SELECT id FROM ttrss_users WHERE login = '$user' LIMIT 1");
		
		if(db_num_rows($result) == 1) {
			$user_id = db_fetch_result($result, 0, "id");

			// check if there is a pending request
			$result = $this->dbh->query("SELECT * FROM ttrss_social_friends WHERE friend_id = '$user_id' AND user_id = '$uid' LIMIT 1");

			if(db_num_rows($result) == 1) {
				$status = db_fetch_result($result, 0, "status");

				switch ($status) {
				    case "pending":
				        echo "$user didn't accept your request yet. Please be patient...";
				        break;
				    case "rejected":
				        echo "Sorry, $user has already rejected your friend request.";
				        break;
				    case "accepted":
				        echo "Great, $user and you are already friends!";
				        break;
				}
				return;
			}
		} else {
			echo "User '$user' wasn't found. ";
			return;
		}

		$this->dbh->query("INSERT INTO ttrss_social_friends (user_id, friend_id, status) VALUES ($uid, $user_id, 'pending')");
		echo "You have requested friendship with $user. If $user accepts your request, you can share articles and comment on them!";
	}

	function rejectUser() {
		$user_id = $this->dbh->db_escape_string($_POST["user"]);
		$uid = $_SESSION["uid"];
		
		$result = $this->dbh->query("UPDATE ttrss_social_friends SET status = 'rejected'");
		echo "Rejected request.";
	}

	function acceptUser() {
		$user_id = $this->dbh->db_escape_string($_POST["user"]);
		$uid = $_SESSION["uid"];
		
		$result = $this->dbh->query("UPDATE ttrss_social_friends SET status = 'accepted'");
		echo "Accepted request.";
	}

	function get_prefs_js() {
		return file_get_contents(dirname(__FILE__) . "/prefs.js");
	}

	function get_js() {
		return file_get_contents(dirname(__FILE__) . "/social.js");
	}

	function hook_article_button($line) {
		return "<img src=\"plugins/social/comment.gif\"
			style=\"cursor : pointer\" style=\"cursor : pointer\"
			onclick=\"addArticleComment(".$line["id"].")\"
			class='tagsPic' title='".__('Add comment')."'>";
	}

	function hook_render_article($article) {
		return $this->hook_render_article_cdm($article);
	}

	function hook_render_article_cdm($article) {
		$comments = "";

		$result = $this->dbh->query("SELECT c.*,u.login FROM ttrss_social_comments c LEFT JOIN ttrss_users u ON c.user_id = u.id WHERE entry_id = ".$article["id"]."");

		while ($row = db_fetch_assoc($result)) {
			$user = $row["login"];
			$comment = $row["comment"];
			$created = $row["created"];
			$comments .= <<<HTML
			<div class='social_message'>
				<span class='social_comment'>$comment</span>
				<span class='social_user'>$user</span>
				<span class='social_created'>($created)</span>
			</div>
			<div></div>
HTML;
		}

		$result = $this->dbh->query("SELECT e.ref_id,e.owner_uid,u.login FROM ttrss_user_entries e LEFT JOIN ttrss_users u ON e.owner_uid = u.id WHERE e.ref_id = ".$article["id"]."");
		$user_name = db_fetch_result($result, 0, "login");

		$article["content"] = $article["content"] . $comments;
		//$article["feed_title"] = "{$user_name}'s Social";
		$article["orig_feed_id"] = 0;
		$article["feed_id"] = 0;

		return $article;
	}

	function add() {
		// param is the article ref_id
		$param = $this->dbh->db_escape_string($_REQUEST['param']);

		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"id\" value=\"$param\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"op\" value=\"pluginhandler\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"method\" value=\"addComment\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"plugin\" value=\"social\">";

		print "<table width='100%'><tr><td>";
		print "<textarea dojoType=\"dijit.form.SimpleTextarea\"
			style='font-size : 12px; width : 100%; height: 100px;'
			placeHolder='body#ttrssMain { font-size : 14px; };'
			name='comment'></textarea>";
		print "</td></tr></table>";

		print "<div class='dlgButtons'>";
		print "<button dojoType=\"dijit.form.Button\"
			onclick=\"dijit.byId('addCommentDlg').execute()\">".__('Save')."</button> ";
		print "<button dojoType=\"dijit.form.Button\"
			onclick=\"dijit.byId('addCommentDlg').hide()\">".__('Cancel')."</button>";
		print "</div>";

	}

	function addComment() {
		$article_id = $this->dbh->db_escape_string($_REQUEST["id"]);
		$comment = trim(strip_tags($this->dbh->db_escape_string($_REQUEST["comment"])));
		$uid = $_SESSION["uid"];

		$this->dbh->query("INSERT INTO ttrss_social_comments (entry_id, user_id, created, comment) 
								VALUES ($article_id, $uid, Now(), '$comment')");;
		
		label_add_article("Social - Comments", $uid);

		$this->dbh->query("UPDATE ttrss_user_entries 
								SET unread = true
								WHERE ref_id = '$article_id' 
									AND owner_uid = $uid");
		return;

		print json_encode(array("note" => $comment,
				"raw_length" => mb_strlen($comment)));
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;
		$uid = $_SESSION["uid"];

		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__("Social")."\">";


		print "<h2>Friends</h2>";

		$this->print_friend_requests();

		$result = $this->dbh->query("SELECT login FROM ttrss_users LIMIT 0, 500");
		$users = "";
		if(db_num_rows($result) != 0) {
			while ($row = db_fetch_assoc($result)) {
				$users .= "<option value='".$row["login"]."' />";
			}
		}

		echo <<<HTML
		<h2>Request friendship</h2>

		<form dojoType="dijit.form.Form">
			<script type="dojo/method" event="onSubmit" args="evt">
				evt.preventDefault();
				if (this.validate()) {
					console.log(dojo.objectToQuery(this.getValues()));
					new Ajax.Request('backend.php', {
						parameters: dojo.objectToQuery(this.getValues()),
						onComplete: function(transport) {
							notify_info(transport.responseText);
						}
					});
				}
			</script>
			<input dojoType="dijit.form.TextBox" style="display: none" name="op" value="pluginhandler">
			<input dojoType="dijit.form.TextBox" style="display: none" name="method" value="requestUser">
			<input dojoType="dijit.form.TextBox" style="display: none" name="plugin" value="social">
			<table width="100%" class="prefPrefsList">
				<tr>
					<td width="20%" valign="top">Add a friend by its username</td>
					<td width="80%" class="prefValue">
						<input dojoType="dijit.form.ValidationTextBox" required="1" name="user" value="$value" list="users"><br/>
						<button dojoType="dijit.form.Button" type="submit">Request user</button>
						<datalist id="users">$users</datalist>
					</td>
				</tr>
			</table>
			
		</form>
		</div> <!-- closing the pane! -->
HTML;
	}

	public function print_friend_requests() {
		$uid = $_SESSION["uid"];

		$this->dbh->query("CREATE TABLE IF NOT EXISTS ttrss_social_comments (id INT NOT NULL AUTO_INCREMENT, PRIMARY KEY(id), entry_id INT, user_id INT, created DATETIME, comment TEXT)");
		$this->dbh->query("CREATE TABLE IF NOT EXISTS ttrss_social_friends (id INT NOT NULL AUTO_INCREMENT, PRIMARY KEY(id), user_id INT, friend_id INT, status ENUM('pending', 'rejected' , 'accepted') NOT NULL)");
		
		//$result = db_query($this->link, "SELECT id FROM ttrss_labels2 WHERE caption = 'Social - Comments' AND owner_uid = '$uid' LIMIT 1");
		//if(db_num_rows($result) == 0) {
		//	label_create($this->link, "Social - Comments", '', '', $uid);
		//	return;
		//}

		$result = $this->dbh->query("SELECT f.*, u.login FROM ttrss_social_friends f LEFT JOIN ttrss_users u ON u.id = f.user_id WHERE friend_id = '$uid' AND status = 'pending'");
		if(db_num_rows($result) == 0) {
			echo "No pending requests yet.";
		} else {
		echo "<table>";
			while ($row = db_fetch_assoc($result)) {
				$user_id = $row["user_id"];
				$user_login = $row["login"];
				$status = $row["status"];
				echo <<<HTML
					<tr>
						<td width="300px">$user_login</td>
						<td width="100px">($status)</td>
HTML;
				if($status != "rejected")
				echo <<<HTML
						<td>
							<form dojoType="dijit.form.Form">
								<script type="dojo/method" event="onSubmit" args="evt">
									evt.preventDefault();
									if (this.validate()) {
										console.log(dojo.objectToQuery(this.getValues()));
										new Ajax.Request('backend.php', {
											parameters: dojo.objectToQuery(this.getValues()),
											onComplete: function(transport) {
												notify_info(transport.responseText);
											}
										});
									}
								</script>
								<input dojoType="dijit.form.TextBox" style="display: none" name="op" value="pluginhandler">
								<input dojoType="dijit.form.TextBox" style="display: none" name="method" value="rejectUser">
								<input dojoType="dijit.form.TextBox" style="display: none" name="plugin" value="social">
								<input dojoType="dijit.form.TextBox" style="display: none" name="user" value="$user_id">
								<button dojoType="dijit.form.Button" type="submit">Reject</button>
							</form>
						</td>
HTML;
				if($status != "accepted")
				echo <<<HTML
						<td>
							<form dojoType="dijit.form.Form">
								<script type="dojo/method" event="onSubmit" args="evt">
									evt.preventDefault();
									if (this.validate()) {
										console.log(dojo.objectToQuery(this.getValues()));
										new Ajax.Request('backend.php', {
											parameters: dojo.objectToQuery(this.getValues()),
											onComplete: function(transport) {
												notify_info(transport.responseText);
											}
										});
									}
								</script>
								<input dojoType="dijit.form.TextBox" style="display: none" name="op" value="pluginhandler">
								<input dojoType="dijit.form.TextBox" style="display: none" name="method" value="acceptUser">
								<input dojoType="dijit.form.TextBox" style="display: none" name="plugin" value="social">
								<input dojoType="dijit.form.TextBox" style="display: none" name="user" value="$user_id">
								<button dojoType="dijit.form.Button" type="submit">Accept</button>
							</form>
						</td>
HTML;
				echo <<<HTML
					</tr>
HTML;
			}
			echo "</table>";
		}

		echo "<hr/>";

		$result = $this->dbh->query("SELECT f.id, ua.login login_from, f.status, ub.login login_to
											FROM ttrss_social_friends f
											LEFT JOIN ttrss_users ua ON ua.id = f.user_id
											LEFT JOIN ttrss_users ub ON ub.id = f.friend_id
											WHERE f.user_id = 2 OR f.friend_id = 2");
		if(db_num_rows($result) == 0) {
			echo "No pending requests yet.";
		} else {
		echo "<table>";
			while ($row = db_fetch_assoc($result)) {
				$from = $row["login_from"];
				$to = $row["login_to"];
				$id = $row["id"];
				$status = $row["status"];

				if($from == $_SESSION["name"]) $from = "you";
				if($to == $_SESSION["name"]) $to = "you";

				//if($row["user_id"] == $uid) echo "$user_login has $status you";
				//else echo "you $status $user_login";
				//echo " $id <br/>\n";
				echo "$from $status $to<br/>";
			}
		}

	}
}
?>
