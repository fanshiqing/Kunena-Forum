<?php
/**
 * Kunena Component
 * @package Kunena.Site
 * @subpackage Controllers
 *
 * @copyright (C) 2008 - 2015 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

/**
 * Kunena Topic Controller
 *
 * @since		2.0
 */
class KunenaControllerTopic extends KunenaController {
	public function __construct($config = array()) {
		parent::__construct($config);
		$this->catid = JRequest::getInt('catid', 0);
		$this->return = JRequest::getInt('return', $this->catid);
		$this->id = JRequest::getInt('id', 0);
		$this->mesid = JRequest::getInt('mesid', 0);
	}

	/**
	 * Get attachments attached to a message with AJAX.
	 *
	 * @throws RuntimeException
	 *
	 * @return string
	 */
	public function loadattachments()
	{
		// Only support JSON requests.
		if ($this->input->getWord('format', 'html') != 'json')
		{
			throw new RuntimeException(JText::_('Bad Request'), 400);
		}

		if (!JSession::checkToken('request'))
		{
			throw new RuntimeException(JText::_('Forbidden'), 403);
		}

		$mes_id = $this->input->getInt('mes_id', 0);
		$attachments = KunenaAttachmentHelper::getByMessage($mes_id);
		$list = array();

		foreach ($attachments as $attach)
		{
			$object = new stdClass;
			$object->id = $attach->id;
			$object->size = round($attach->size / '1024', 0);
			$object->name = $attach->filename;
			$object->folder = $attach->folder;
			$object->caption = $attach->caption;
			$object->type = $attach->filetype;
			$object->path = $attach->getUrl();
			$list['files'][] = $object;
		}

		header('Content-type: application/json');
		header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
		header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
		header("Cache-Control: no-store, no-cache, must-revalidate");
		header("Cache-Control: post-check=0, pre-check=0", false);
		header("Pragma: no-cache");
		while(@ob_end_clean());
		echo json_encode($list);
		jexit();
	}

	/**
	 * Remove files with AJAX.
	 *
	 * @throws RuntimeException
	 *
	 * @return string
	 */
	public function removeattachments()
	{
		// Only support JSON requests.
		if ($this->input->getWord('format', 'html') != 'json')
		{
			throw new RuntimeException(JText::_('Bad Request'), 400);
		}

		if (!JSession::checkToken('request'))
		{
			throw new RuntimeException(JText::_('Forbidden'), 403);
		}

		$attach_id = $this->input->getInt('file_id', 0);
		$success = array();
		$instance = KunenaAttachmentHelper::get($attach_id);
		$success['result'] = $instance->delete();
		unset($instance);
		header('Content-type: application/json');
		header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
		header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
		header("Cache-Control: no-store, no-cache, must-revalidate");
		header("Cache-Control: post-check=0, pre-check=0", false);
		header("Pragma: no-cache");
		while(@ob_end_clean());
		echo json_encode($success);
		jexit();
	}

	/**
	 * Upload files with AJAX.
	 *
	 * @throws RuntimeException
	 */
	public function upload() {
		// Only support JSON requests.
		if ($this->input->getWord('format', 'html') != 'json')
		{
			throw new RuntimeException(JText::_('Bad Request'), 400);
		}

		$upload = KunenaUpload::getInstance();

		// We are converting all exceptions into JSON.
		try
		{
			if (!JSession::checkToken('request'))
			{
				throw new RuntimeException(JText::_('Forbidden'), 403);
			}

			$me = KunenaUserHelper::getMyself();
			$catid = $this->input->getInt('catid', 0);
			$mesid = $this->input->getInt('mesid', 0);

			if ($mesid)
			{
				$message = KunenaForumMessageHelper::get($mesid);
				$message->tryAuthorise('attachment.create');
				$category = $message->getCategory();
			}
			else
			{
				$category = KunenaForumCategoryHelper::get($catid);
				// TODO: Some room for improvements in here... (maybe ask user to pick up category first)
				if ($category->id) $category->tryAuthorise('topic.post.attachment.create');
			}

			$caption = $this->input->getString('caption');
			$options = array(
				'filename' => $this->input->getString('filename'),
				'size' => $this->input->getInt('size'),
				'mime' => $this->input->getString('mime'),
				'hash' => $this->input->getString('hash'),
				'chunkStart' => $this->input->getInt('chunkStart', 0),
				'chunkEnd' => $this->input->getInt('chunkEnd', 0),
			);

			// Upload!
			$upload->addExtensions(KunenaAttachmentHelper::getExtensions($category->id, $me->userid));
			$response = (object) $upload->ajaxUpload($options);

			if (!empty($response->completed))
			{
				// We have it all, lets create the attachment.
				$uploadFile = $upload->getProtectedFile();
				list($basename, $extension) = $upload->splitFilename();
				$attachment = new KunenaAttachment;
				$attachment->bind(
					array(
						'mesid' => 0,
						'userid' => (int) $me->userid,
						'protected' => null,
						'hash' => $response->hash,
						'size' => $response->size,
						'folder' => null,
						'filetype' => $response->mime,
						'filename' => null,
						'filename_real' => $response->filename,
						'caption' => $caption,
					)
				);

				// Resize image if needed.
				if ($attachment->isImage())
				{
					$imageInfo = KunenaImage::getImageFileProperties($uploadFile);
					$config = KunenaConfig::getInstance();

					if ($imageInfo->width > $config->imagewidth || $imageInfo->height > $config->imageheight)
					{
						// Calculate quality for both JPG and PNG.
						$quality = $config->imagequality;
						if ($quality < 1 || $quality > 100) $quality = 70;
						if ($imageInfo->type == IMAGETYPE_PNG) $quality = intval(($quality-1)/10);

						$image = new KunenaImage($uploadFile);
						$image = $image->resize($config->imagewidth, $config->imageheight, false);

						$options = array('quality' => $quality);
						$image->toFile($uploadFile, $imageInfo->type, $options);

						unset($image);

						$attachment->hash = md5_file($uploadFile);
						$attachment->size = filesize($uploadFile);
					}
				}

				$attachment->saveFile($uploadFile, $basename, $extension, true);

				// Set id and override response variables just in case if attachment was modified.
				$response->id = $attachment->id;
				$response->hash = $attachment->hash;
				$response->size = $attachment->size;
				$response->mime = $attachment->filetype;
				$response->filename = $attachment->filename_real;
			}
		}
		catch (Exception $response)
		{
			$upload->cleanup();

			// Use the exception as the response.
		}

		header('Content-type: application/json');
		header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
		header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
		header("Cache-Control: no-store, no-cache, must-revalidate");
		header("Cache-Control: post-check=0, pre-check=0", false);
		header("Pragma: no-cache");

		while(@ob_end_clean());

		echo $upload->ajaxResponse($response);
		jexit();
	}

	public function post() {
		$this->id = JRequest::getInt('parentid', 0);
		$fields = array (
			'catid' => $this->catid,
			'name' => JRequest::getString ( 'authorname', $this->me->getName () ),
			'email' => JRequest::getString ( 'email', null ),
			'subject' => JRequest::getVar('subject', null, 'POST', 'string', JREQUEST_ALLOWRAW), // RAW input
			'message' => JRequest::getVar('message', null, 'POST', 'string', JREQUEST_ALLOWRAW), // RAW input
			'icon_id' => JRequest::getInt ( 'topic_emoticon', null ),
			'anonymous' => JRequest::getInt ( 'anonymous', 0 ),
			'poll_title' => JRequest::getString ( 'poll_title', '' ),
			'poll_options' => JRequest::getVar('polloptionsID', array (), 'post', 'array'), // Array of key => string
			'poll_time_to_live' => JRequest::getString ( 'poll_time_to_live', 0 ),
			'tags' => JRequest::getString ( 'tags', null ),
			'mytags' => JRequest::getString ( 'mytags', null ),
			'subscribe' => JRequest::getInt ( 'subscribeMe', 0 )
		);

		$this->app->setUserState('com_kunena.postfields', $fields);

		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		// Load language file from the template.
		KunenaFactory::getTemplate()->loadLanguage();

		$captcha = KunenaSpamRecaptcha::getInstance();
		if ($captcha->enabled()) {
			$success = $captcha->verify();
			if ( !$success ) {
				$this->app->enqueueMessage ( $captcha->getError(), 'error' );
				$this->setRedirectBack();
				return;
			}
		}

		if (!$this->id) {
			// Create topic
			$category = KunenaForumCategoryHelper::get($this->catid);
			if (!$category->authorise('topic.create')) {
				$this->app->enqueueMessage ( $category->getError(), 'notice' );
				$this->setRedirectBack();
				return;
			}
			list ($topic, $message) = $category->newTopic($fields);
		} else {
			// Reply topic
			$parent = KunenaForumMessageHelper::get($this->id);
			if (!$parent->authorise('reply')) {
				$this->app->enqueueMessage ( $parent->getError(), 'notice' );
				$this->setRedirectBack();
				return;
			}
			list ($topic, $message) = $parent->newReply($fields);
			$category = $topic->getCategory();
		}

		// Redirect to full reply instead.
		if (JRequest::getString('fullreply')) {
			$this->setRedirect(KunenaRoute::_("index.php?option=com_kunena&view=topic&layout=reply&catid={$fields->catid}&id={$parent->getTopic()->id}&mesid={$parent->id}", false));
			return;
		}

		// Flood protection
		if ($this->config->floodprotection && ! $this->me->isModerator($category)) {
			$timelimit = JFactory::getDate()->toUnix() - $this->config->floodprotection;
			$ip = $_SERVER ["REMOTE_ADDR"];

			$db = JFactory::getDBO();
			$db->setQuery ( "SELECT COUNT(*) FROM #__kunena_messages WHERE ip={$db->Quote($ip)} AND time>{$db->quote($timelimit)}" );
			$count = $db->loadResult ();
			if (KunenaError::checkDatabaseError() || $count) {
				$this->app->enqueueMessage ( JText::sprintf ( 'COM_KUNENA_POST_TOPIC_FLOOD', $this->config->floodprotection) );
				$this->setRedirectBack();
				return;
			}
		}

		// Set topic icon if permitted
		if ($this->config->topicicons && isset($fields['icon_id']) && $topic->authorise('edit', null, false)) {
			$topic->icon_id = $fields['icon_id'];
		}

		// Remove IP address
		// TODO: Add administrator tool to remove all tracked IP addresses (from the database)
		if (!$this->config->iptracking) {
			$message->ip = '';
		}
		// If requested: Make message to be anonymous
		if ($fields['anonymous'] && $message->getCategory()->allow_anonymous) {
			$message->makeAnonymous();
		}

		// If configured: Hold posts from guests
		if ( !$this->me->userid && $this->config->hold_guest_posts) {
			$message->hold = 1;
		}
		// If configured: Hold posts from users
		if ( $this->me->userid && !$this->me->isModerator($category) && $this->me->posts < $this->config->hold_newusers_posts ) {
			$message->hold = 1;
		}

		// Prevent user abort from this point in order to maintain data integrity.
		@ignore_user_abort(true);

		// Mark attachments to be added or deleted.
		$attachments = JRequest::getVar ( 'attachments', array(), 'post', 'array' );
		$attachment = JRequest::getVar ( 'attachment', array(), 'post', 'array' );
		$message->addAttachments(array_keys(array_intersect_key($attachments, $attachment)));
		$message->removeAttachments(array_keys(array_diff_key($attachments, $attachment)));

		// Legacy way to upload new attachments
		foreach ($_FILES as $key => $file)
		{
			$intkey = 0;
			if (preg_match('/\D*(\d+)/', $key, $matches))
				$intkey = (int)$matches[1];

			if ($file['error'] != UPLOAD_ERR_NO_FILE)
			{
				$message->uploadAttachment($intkey, $key, $this->catid);
			}
		}

		// Make sure that message has visible content (text, images or objects) to be shown.
		$text = KunenaHtmlParser::parseBBCode($message->message);
		if (!preg_match('!(<img |<object )!', $text)) {
			$text = trim(JFilterOutput::cleanText($text));
		}
		if (!$text) {
			$this->app->enqueueMessage ( JText::_('COM_KUNENA_LIB_TABLE_MESSAGES_ERROR_NO_MESSAGE'), 'error' );
			$this->setRedirectBack();
			return;
		}

		// Activity integration
		$activity = KunenaFactory::getActivityIntegration();
		if ( $message->hold == 0 ) {
			if (!$topic->exists()) {
				$activity->onBeforePost($message);
			} else {
				$activity->onBeforeReply($message);
			}
		}

		// Save message
		$success = $message->save ();
		if (! $success) {
			$this->app->enqueueMessage ( $message->getError (), 'error' );
			$this->setRedirectBack();
			return;
		}

		// Message has been sent, we can now clear saved form
		$this->app->setUserState('com_kunena.postfields', null);

		// Display possible warnings (upload failed etc)
		foreach ( $message->getErrors () as $warning ) {
			$this->app->enqueueMessage ( $warning, 'notice' );
		}

		// Create Poll
		$poll_title = $fields['poll_title'];
		$poll_options = $fields['poll_options'];
		if (! empty ( $poll_options ) && ! empty ( $poll_title )) {
			if ($topic->authorise('poll.create', null, false)) {
				$poll = $topic->getPoll();
				$poll->title = $poll_title;
				$poll->polltimetolive = $fields['poll_time_to_live'];
				$poll->setOptions($poll_options);
				if (!$poll->save()) {
					$this->app->enqueueMessage ( $poll->getError(), 'notice' );
				} else {
					$topic->poll_id = $poll->id;
					$topic->save();
					$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POLL_CREATED' ) );
				}
			} else {
				$this->app->enqueueMessage ( $topic->getError(), 'notice' );
			}
		}

		// Update Tags
		$this->updateTags($message->thread, $fields['tags'], $fields['mytags']);

		$message->sendNotification();

		//now try adding any new subscriptions if asked for by the poster
		$usertopic = $topic->getUserTopic();
		if ($fields['subscribe'] && !$usertopic->subscribed) {
			if ($topic->subscribe(1)) {
				$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_SUBSCRIBED_TOPIC' ) );

				// Activity integration
				$activity = KunenaFactory::getActivityIntegration();
				$activity->onAfterSubscribe($topic, 1);
			} else {
				$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_NO_SUBSCRIBED_TOPIC' ) .' '. $topic->getError() );
			}
		}

		if ($message->hold == 1) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_SUCCES_REVIEW' ) );
		} else {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_SUCCESS_POSTED' ) );
		}

		$category = KunenaForumCategoryHelper::get($this->return);
		if ($message->authorise('read', null, false) && $this->id) {
			$this->setRedirect ( $message->getUrl($category, false) );
		} elseif ($topic->authorise('read', null, false)) {
			$this->setRedirect ( $topic->getUrl($category, false) );
		} else {
			$this->setRedirect ( $category->getUrl(null, false) );
		}
	}

	public function edit() {
		$this->id = JRequest::getInt('mesid', 0);

		$message = KunenaForumMessageHelper::get($this->id);
		$topic = $message->getTopic();
		$fields = array (
			'name' => JRequest::getString ( 'authorname', $message->name ),
			'email' => JRequest::getString ( 'email', $message->email ),
			'subject' => JRequest::getVar('subject', $message->subject, 'POST', 'string', JREQUEST_ALLOWRAW), // RAW input
			'message' => JRequest::getVar('message', $message->message, 'POST', 'string', JREQUEST_ALLOWRAW), // RAW input
			'modified_reason' => JRequest::getString ( 'modified_reason', $message->modified_reason ),
			'icon_id' => JRequest::getInt ( 'topic_emoticon', $topic->icon_id ),
			'anonymous' => JRequest::getInt ( 'anonymous', 0 ),
			'poll_title' => JRequest::getString ( 'poll_title', null ),
			'poll_options' => JRequest::getVar('polloptionsID', array (), 'post', 'array'), // Array of key => string
			'poll_time_to_live' => JRequest::getString ( 'poll_time_to_live', 0 ),
			'tags' => JRequest::getString ( 'tags', null ),
			'mytags' => JRequest::getString ( 'mytags', null )
		);

		if (! JSession::checkToken('post')) {
			$this->app->setUserState('com_kunena.postfields', $fields);
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		if (!$message->authorise('edit')) {
			$this->app->setUserState('com_kunena.postfields', $fields);
			$this->app->enqueueMessage ( $message->getError(), 'notice' );
			$this->setRedirectBack();
			return;
		}

		// Load language file from the template.
		KunenaFactory::getTemplate()->loadLanguage();

		// Update message contents
		$message->edit ( $fields );
		// If requested: Make message to be anonymous
		if ($fields['anonymous'] && $message->getCategory()->allow_anonymous) {
			$message->makeAnonymous();
		}

		// Prevent user abort from this point in order to maintain data integrity.
		@ignore_user_abort(true);

		// Mark attachments to be added or deleted.
		$attachments = JRequest::getVar ( 'attachments', array(), 'post', 'array' );
		$attachment = JRequest::getVar ( 'attachment', array(), 'post', 'array' );

        $addList = array_keys(array_intersect_key($attachments, $attachment));
        JArrayHelper::toInteger($addList);
        $removeList = array_keys(array_diff_key($attachments, $attachment));
        JArrayHelper::toInteger($removeList);

		$message->addAttachments($addList);
		$message->removeAttachments($removeList);

		// Upload new attachments
		foreach ($_FILES as $key=>$file) {
			$intkey = 0;
			if (preg_match('/\D*(\d+)/', $key, $matches))
				$intkey = (int)$matches[1];
			if ($file['error'] != UPLOAD_ERR_NO_FILE) $message->uploadAttachment($intkey, $key, $this->catid);
		}

		// Set topic icon if permitted
		if ($this->config->topicicons && isset($fields['icon_id']) && $topic->authorise('edit', null, false)) {
			$topic->icon_id = $fields['icon_id'];
		}

		// Check if we are editing first post and update topic if we are!
		if ($topic->first_post_id == $message->id) {
			$topic->subject = $fields['subject'];
		}

		// If user removed all the text and message doesn't contain images or objects, delete the message instead.
		$text = KunenaHtmlParser::parseBBCode($message->message);
		if (!preg_match('!(<img |<object )!', $text)) {
			$text = trim(JFilterOutput::cleanText($text));
		}
		if (!$text) {
			// Reload message (we don't want to change it).
			$message->load();
			if ($message->publish(KunenaForum::DELETED)) {
				$this->app->enqueueMessage(JText::_('COM_KUNENA_POST_SUCCESS_DELETE'));
			} else {
				$this->app->enqueueMessage($message->getError(), 'notice');
			}
			$this->setRedirect($message->getUrl($this->return, false));
			return;
		}

		// Activity integration
		$activity = KunenaFactory::getActivityIntegration();
		$activity->onBeforeEdit($message);

		// Save message
		$success = $message->save ();
		if (! $success) {
			$this->app->setUserState('com_kunena.postfields', $fields);
			$this->app->enqueueMessage ( $message->getError (), 'error' );
			$this->setRedirectBack();
			return;
		}
		// Display possible warnings (upload failed etc)
		foreach ( $message->getErrors () as $warning ) {
			$this->app->enqueueMessage ( $warning, 'notice' );
		}

		$poll_title = $fields['poll_title'];
		if ($poll_title !== null && $message->id == $topic->first_post_id) {
			// Save changes into poll
			$poll_options = $fields['poll_options'];
			$poll = $topic->getPoll();
			if (! empty ( $poll_options ) && ! empty ( $poll_title )) {
				$poll->title = $poll_title;
				$poll->polltimetolive = $fields['poll_time_to_live'];
				$poll->setOptions($poll_options);
				if (!$topic->poll_id) {
					// Create a new poll
					if (!$topic->authorise('poll.create')) {
						$this->app->enqueueMessage ( $topic->getError(), 'notice' );
					} elseif (!$poll->save()) {
						$this->app->enqueueMessage ( $poll->getError(), 'notice' );
					} else {
						$topic->poll_id = $poll->id;
						$topic->save();
						$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POLL_CREATED' ) );
					}
				} else {
					// Edit existing poll
					if (!$topic->authorise('poll.edit')) {
						$this->app->enqueueMessage ( $topic->getError(), 'notice' );
					} elseif (!$poll->save()) {
						$this->app->enqueueMessage ( $poll->getError(), 'notice' );
					} else {
						$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POLL_EDITED' ) );
					}
				}
			} elseif ($poll->exists() && $topic->authorise('poll.edit')) {
				// Delete poll
				if (!$topic->authorise('poll.delete')) {
					// Error: No permissions to delete poll
					$this->app->enqueueMessage ( $topic->getError(), 'notice' );
				} elseif (!$poll->delete()) {
					$this->app->enqueueMessage ( $poll->getError(), 'notice' );
				} else {
					$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POLL_DELETED' ) );
				}
			}
		}

		// Update Tags
		$this->updateTags($message->thread, $fields['tags'], $fields['mytags']);

		$activity->onAfterEdit($message);

		$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_SUCCESS_EDIT' ) );
		if ($message->hold == 1) {
			// If user cannot approve message by himself, send email to moderators.
			if (!$topic->authorise('approve')) $message->sendNotification();
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_GEN_MODERATED' ) );
		}

		$this->setRedirect($message->getUrl($this->return, false));
	}

	public function thankyou() {
		$type = JRequest::getString('task');
		$this->setThankyou($type);

		$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_THANKYOU_SUCCESS' ) );
	}

	public function unthankyou() {
		$type = JRequest::getString('task');
		$this->setThankyou($type);

		$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_THANKYOU_REMOVED_SUCCESS' ) );
	}

	protected function setThankyou($type){
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$message = KunenaForumMessageHelper::get($this->mesid);
		if (!$message->authorise($type)) {
			$this->app->enqueueMessage ( $message->getError() );
			$this->setRedirectBack();
			return;
		}

		$category = KunenaForumCategoryHelper::get($this->catid);
		$thankyou = KunenaForumMessageThankyouHelper::get($this->mesid);
		$activityIntegration = KunenaFactory::getActivityIntegration();
		if ( $type== 'thankyou') {
			if (!$thankyou->save ( $this->me )) {
				$this->app->enqueueMessage ( $thankyou->getError() );
				$this->setRedirectBack();
				return;
			}
			$activityIntegration->onAfterThankyou($this->me->userid, $message->userid, $message);
		} else {
			$userid = JRequest::getInt('userid','0');
			if (!$thankyou->delete ( $userid )) {
				$this->app->enqueueMessage ( $thankyou->getError() );
				$this->setRedirectBack();
				return;
			}
			$activityIntegration->onAfterUnThankyou($this->me->userid, $userid, $message);
		}
		$this->setRedirect($message->getUrl($category->exists() ? $category->id : $message->catid, false));
	}

	public function subscribe() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$topic = KunenaForumTopicHelper::get($this->id);
		if ($topic->authorise('read') && $topic->subscribe(1)) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_SUBSCRIBED_TOPIC' ) );

			// Activity integration
			$activity = KunenaFactory::getActivityIntegration();
			$activity->onAfterSubscribe($topic, 1);
		} else {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_NO_SUBSCRIBED_TOPIC' ) .' '. $topic->getError(), 'notice' );
		}
		$this->setRedirectBack();
	}

	public function unsubscribe() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$topic = KunenaForumTopicHelper::get($this->id);
		if ($topic->authorise('read') && $topic->subscribe(0)) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_UNSUBSCRIBED_TOPIC' ) );

			// Activity integration
			$activity = KunenaFactory::getActivityIntegration();
			$activity->onAfterSubscribe($topic, 0);
		} else {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_NO_UNSUBSCRIBED_TOPIC' ) .' '. $topic->getError(), 'notice' );
		}
		$this->setRedirectBack();
	}

	public function favorite() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$topic = KunenaForumTopicHelper::get($this->id);
		if ($topic->authorise('read') && $topic->favorite(1)) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_FAVORITED_TOPIC' ) );

			// Activity integration
			$activity = KunenaFactory::getActivityIntegration();
			$activity->onAfterFavorite($topic, 1);
		} else {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_NO_FAVORITED_TOPIC' ) .' '. $topic->getError(), 'notice' );
		}
		$this->setRedirectBack();
	}

	public function unfavorite() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$topic = KunenaForumTopicHelper::get($this->id);
		if ($topic->authorise('read') && $topic->favorite(0)) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_UNFAVORITED_TOPIC' ) );

			// Activity integration
			$activity = KunenaFactory::getActivityIntegration();
			$activity->onAfterFavorite($topic, 0);
		} else {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_NO_UNFAVORITED_TOPIC' ) .' '. $topic->getError(), 'notice' );
		}
		$this->setRedirectBack();
	}

	public function sticky() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$topic = KunenaForumTopicHelper::get($this->id);
		if (!$topic->authorise('sticky')) {
			$this->app->enqueueMessage ( $topic->getError(), 'notice' );
		} elseif ($topic->sticky(1)) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_STICKY_SET' ) );

			// Activity integration
			$activity = KunenaFactory::getActivityIntegration();
			$activity->onAfterSticky($topic, 1);
		} else {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_STICKY_NOT_SET' ) );
		}
		$this->setRedirectBack();
	}

	public function unsticky() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$topic = KunenaForumTopicHelper::get($this->id);
		if (!$topic->authorise('sticky')) {
			$this->app->enqueueMessage ( $topic->getError(), 'notice' );
		} elseif ($topic->sticky(0)) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_STICKY_UNSET' ) );

			// Activity integration
			$activity = KunenaFactory::getActivityIntegration();
			$activity->onAfterSticky($topic, 0);
		} else {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_STICKY_NOT_UNSET' ) );
		}
		$this->setRedirectBack();
	}

	public function lock() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$topic = KunenaForumTopicHelper::get($this->id);
		if (!$topic->authorise('lock')) {
			$this->app->enqueueMessage ( $topic->getError(), 'notice' );
		} elseif ($topic->lock(1)) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_LOCK_SET' ) );

			// Activity integration
			$activity = KunenaFactory::getActivityIntegration();
			$activity->onAfterLock($topic, 1);
		} else {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_LOCK_NOT_SET' ) );
		}
		$this->setRedirectBack();
	}

	public function unlock() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$topic = KunenaForumTopicHelper::get($this->id);
		if (!$topic->authorise('lock')) {
			$this->app->enqueueMessage ( $topic->getError(), 'notice' );
		} elseif ($topic->lock(0)) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_LOCK_UNSET' ) );

			// Activity integration
			$activity = KunenaFactory::getActivityIntegration();
			$activity->onAfterLock($topic, 0);
		} else {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_LOCK_NOT_UNSET' ) );
		}
		$this->setRedirectBack();
	}

	public function delete() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		if ($this->mesid) {
			// Delete message
			$target = KunenaForumMessageHelper::get($this->mesid);
			$hold = KunenaForum::DELETED;
			$msg = JText::_ ( 'COM_KUNENA_POST_SUCCESS_DELETE' );
		} else {
			// Delete topic
			$target = KunenaForumTopicHelper::get($this->id);
			$hold = KunenaForum::TOPIC_DELETED;
			$msg = JText::_ ( 'COM_KUNENA_TOPIC_SUCCESS_DELETE' );
		}
		if ($target->authorise('delete') && $target->publish($hold)) {
			$this->app->enqueueMessage ( $msg );
		} else {
			$this->app->enqueueMessage ( $target->getError(), 'notice' );
		}
		if (!$target->authorise('read')) {
			if ($target instanceof KunenaForumMessage && $target->getTopic()->authorise('read')) {
				$target = $target->getTopic();
				// TODO: need to get closest message
				$target = KunenaForumMessageHelper::get($target->last_post_id);
			} else {
				$target = $target->getCategory();
			}
		}
		$this->setRedirect($target->getUrl($this->return, false));
	}

	public function undelete() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		if ($this->mesid) {
			// Undelete message
			$target = KunenaForumMessageHelper::get($this->mesid);
			$msg = JText::_ ( 'COM_KUNENA_POST_SUCCESS_UNDELETE' );
		} else {
			// Undelete topic
			$target = KunenaForumTopicHelper::get($this->id);
			$msg = JText::_ ( 'COM_KUNENA_TOPIC_SUCCESS_UNDELETE' );
		}
		if ($target->authorise('undelete') && $target->publish(KunenaForum::PUBLISHED)) {
			$this->app->enqueueMessage ( $msg );
		} else {
			$this->app->enqueueMessage ( $target->getError(), 'notice' );
		}
		$this->setRedirect($target->getUrl($this->return, false));
	}

	public function permdelete() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		if ($this->mesid) {
			// Delete message
			$target = KunenaForumMessageHelper::get($this->mesid);
			$topic = KunenaForumTopicHelper::get($target->getTopic());
		} else {
			// Delete topic
			$target = $topic = KunenaForumTopicHelper::get($this->id);
		}
		if ($target->authorise('permdelete') && $target->delete()) {
			if ($topic->exists()) {
				$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_SUCCESS_DELETE' ) );
				$url = $topic->getUrl($this->return, false);
			} else {
				$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_TOPIC_SUCCESS_DELETE' ) );
				$url = $topic->getCategory()->getUrl($this->return, false);
			}
		} else {
			$this->app->enqueueMessage ( $target->getError(), 'notice' );
		}
		if (isset($url)) $this->setRedirect($url);
	}

	public function approve() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		// Load language file from the template.
		KunenaFactory::getTemplate()->loadLanguage();

		if ($this->mesid) {
			// Approve message
			$target = KunenaForumMessageHelper::get($this->mesid);
			$message = $target;
		} else {
			// Approve topic
			$target = KunenaForumTopicHelper::get($this->id);
			$message = KunenaForumMessageHelper::get($target->first_post_id);
		}
		if ($target->authorise('approve') && $target->publish(KunenaForum::PUBLISHED)) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_MODERATE_APPROVE_SUCCESS' ) );
			// Only email if message wasn't modified by the author before approval
			// TODO: this is just a workaround for #1862, we need to find better solution.
			$modifiedByAuthor = ($message->modified_by == $message->userid);
			if (!$modifiedByAuthor) $target->sendNotification();
		} else {
			$this->app->enqueueMessage ( $target->getError(), 'notice' );
		}
		$this->setRedirect($target->getUrl($this->return, false));
	}

	public function move() {
		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$topicId = JRequest::getInt('id', 0);
		$messageId = JRequest::getInt('mesid', 0);
		$targetCategory = JRequest::getInt('targetcategory', 0);
		$targetTopic = JRequest::getInt('targettopic', 0);
		if ($targetTopic < 0) $targetTopic = JRequest::getInt('targetid', 0);

		if ($messageId) {
			$message = $object = KunenaForumMessageHelper::get ( $messageId );
			$topic = $message->getTopic();
		} else {
			$topic = $object = KunenaForumTopicHelper::get ( $topicId );
			$message = KunenaForumMessageHelper::get($topic->first_post_id);
		}
		if ($targetTopic) {
			$target = KunenaForumTopicHelper::get( $targetTopic );
		} else {
			$target = KunenaForumCategoryHelper::get( $targetCategory );
		}

		$error = null;
		$targetobject = null;
		if (!$object->authorise ( 'move' )) {
			$error = $object->getError();
		} elseif (!$target->authorise ( 'read' )) {
			$error = $target->getError();
		} else {
			$changesubject = JRequest::getBool ( 'changesubject', false );
			$subject = JRequest::getString ( 'subject', '' );
			$shadow = JRequest::getBool ( 'shadow', false );
			$topic_emoticon = JRequest::getInt ( 'topic_emoticon', null );

			if ($object instanceof KunenaForumMessage) {
				$mode = JRequest::getWord ( 'mode', 'selected' );
				switch ($mode) {
					case 'newer':
						$ids = new JDate($object->time);
						break;
					case 'selected':
					default:
						$ids = $object->id;
						break;
				}
			} else {
				$ids = false;
			}
			$targetobject = $topic->move($target, $ids, $shadow, $subject, $changesubject, $topic_emoticon);
			if (!$targetobject) {
				$error = $topic->getError();
			}
		}
		if ($error) {
			$this->app->enqueueMessage ( $error, 'notice' );
		} else {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_SUCCESS_MOVE' ) );
		}
		if ($targetobject) {
			$this->setRedirect($targetobject->getUrl($this->return, false, 'last'));
		} else {
			$this->setRedirect($topic->getUrl($this->return, false, 'first'));
		}
	}

	function report() {
		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		if (!$this->me->exists() || $this->config->reportmsg == 0) {
			// Deny access if report feature has been disabled or user is guest
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_NO_ACCESS' ), 'notice' );
			$this->setRedirectBack();
			return;
		}

		if (!$this->config->get('send_emails')) {
			// Emails have been disabled
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_EMAIL_DISABLED' ), 'notice' );
			$this->setRedirectBack();
			return;
		}
		if (! $this->config->getEmail() || ! JMailHelper::isEmailAddress ( $this->config->getEmail() )) {
			// Error: email address is invalid
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_EMAIL_INVALID' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		// Get target object for the report
		if ($this->mesid) {
			$message = $target = KunenaForumMessageHelper::get($this->mesid);
			$topic = $target->getTopic();
		} else {
			$topic = $target = KunenaForumTopicHelper::get($this->id);
			$message = KunenaForumMessageHelper::get($topic->first_post_id);
		}
		$messagetext = $message->message;
		$baduser = KunenaFactory::getUser($message->userid);

		if (!$target->authorise('read')) {
			// Deny access if user cannot read target
			$this->app->enqueueMessage ( $target->getError(), 'notice' );
			$this->setRedirectBack();
			return;
		}

		$reason = JRequest::getString ( 'reason' );
		$text = JRequest::getString ( 'text' );

		$template = KunenaTemplate::getInstance();
		if (method_exists($template, 'reportMessage')) {
			$template->reportMessage($message, $reason, $text);
		}

		// Load language file from the template.
		KunenaFactory::getTemplate()->loadLanguage();

		if (empty ( $reason ) && empty ( $text )) {
			// Do nothing: empty subject or reason is empty
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_REPORT_FORG0T_SUB_MES' ) );
			$this->setRedirectBack();
			return;
		} else {
			$acl = KunenaAccess::getInstance();
			$emailToList = $acl->getSubscribers($topic->category_id, $topic->id, false, true, false);

			if (!empty ( $emailToList )) {
				$mailsender = JMailHelper::cleanAddress ( $this->config->board_title . ' ' . JText::_ ( 'COM_KUNENA_FORUM' ) . ': ' . $this->me->getName() );
				$mailsubject = "[" . $this->config->board_title . " " . JText::_ ( 'COM_KUNENA_FORUM' ) . "] " . JText::_ ( 'COM_KUNENA_REPORT_MSG' ) . ": ";
				if ($reason) {
					$mailsubject .= $reason;
				} else {
					$mailsubject .= $topic->subject;
				}

				jimport ( 'joomla.environment.uri' );
				$msglink = JUri::getInstance()->toString(array('scheme', 'host', 'port')) . $target->getPermaUrl(null, false);

				$mail = JFactory::getMailer();
				$mail->setSender(array($this->me->username, $this->me->email));
				$mail->setSubject($mailsubject);

				// Render the email.
				$layout = KunenaLayout::factory('Email/Report')->debug(false)
					->set('mail', $mail)
					->set('message', $message)
					->set('me', $this->me)
					->set('title', $reason)
					->set('content', $text)
					->set('messageLink', $msglink);

				try {
					$body = trim($layout->render());
					$mail->setBody($body);

				} catch (Exception $e) {
					// TODO: Deprecated in 3.1, remove in 4.0
					$mailmessage = "" . JText::_ ( 'COM_KUNENA_REPORT_RSENDER' ) . " {$this->me->username} ({$this->me->name})";
					$mailmessage .= "\n";
					$mailmessage .= "" . JText::_ ( 'COM_KUNENA_REPORT_RREASON' ) . " " . $reason;
					$mailmessage .= "\n";
					$mailmessage .= "" . JText::_ ( 'COM_KUNENA_REPORT_RMESSAGE' ) . " " . $text;
					$mailmessage .= "\n\n";
					$mailmessage .= "" . JText::_ ( 'COM_KUNENA_REPORT_POST_POSTER' ) . " {$baduser->username} ({$baduser->name})";
					$mailmessage .= "\n";
					$mailmessage .= "" . JText::_ ( 'COM_KUNENA_REPORT_POST_SUBJECT' ) . ": " . $topic->subject;
					$mailmessage .= "\n";
					$mailmessage .= "" . JText::_ ( 'COM_KUNENA_REPORT_POST_MESSAGE' ) . "\n-----\n" . KunenaHtmlParser::stripBBCode($messagetext, 0, false);
					$mailmessage .= "\n-----\n\n";
					$mailmessage .= "" . JText::_ ( 'COM_KUNENA_REPORT_POST_LINK' ) . " " . $msglink;
					$mailmessage = JMailHelper::cleanBody ( strtr ( $mailmessage, array ('&#32;' => '' ) ) );

					$mail->setBody($mailmessage);
				}

				$receivers = array();
				foreach ( $emailToList as $emailTo ) {
					if (! $emailTo->email || ! JMailHelper::isEmailAddress ( $emailTo->email ))
						continue;

					$receivers[] = $emailTo->email;
				}

				KunenaEmail::send($mail, $receivers);

				$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_REPORT_SUCCESS' ) );
			} else {
				$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_REPORT_NOT_SEND' ) );
			}
		}
		$this->setRedirect($target->getUrl($this->return, false));
	}

	protected function updateTags($topic, $globalTags, $userTags) {
		$topic = KunenaForumTopicHelper::get($topic);
		if ($userTags !== null) {
			$topic->setKeywords($userTags, $this->me->userid);
		}
		if ($globalTags !== null) {
			$topic->setKeywords($globalTags, false);
		}
	}

	public function vote() {
		if (!JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$vote = JRequest::getInt('kpollradio', '');
		$id = JRequest::getInt ( 'id', 0 );
		$catid = JRequest::getInt ( 'catid', 0 );

		$topic = KunenaForumTopicHelper::get($id);
		$poll = $topic->getPoll();
		if (!$topic->authorise('poll.vote')) {
			$this->app->enqueueMessage ( $topic->getError(), 'error' );
		} elseif (!$this->config->pollallowvoteone || !$poll->getMyVotes()) {
			// Give a new vote
			$success = $poll->vote($vote);
			if ( !$success ) {
				$this->app->enqueueMessage ( $poll->getError(), 'error' );
			} else {
				$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_TOPIC_VOTE_SUCCESS' ) );
			}
		} else {
			// Change existing vote
			$success = $poll->vote($vote, true);
			if ( !$success ) {
				$this->app->enqueueMessage ( $poll->getError(), 'error' );
			} else {
				$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_TOPIC_VOTE_CHANGED_SUCCESS' ) );
			}
		}

		$this->setRedirect($topic->getUrl($this->return, false));
	}

	public function resetvotes() {
		if (!JSession::checkToken('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$topic = KunenaForumTopicHelper::get($this->id);
		$topic->resetvotes();

		$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_TOPIC_VOTE_RESET_SUCCESS' ) );
		$this->setRedirect($topic->getUrl($this->return, false));
	}
}
