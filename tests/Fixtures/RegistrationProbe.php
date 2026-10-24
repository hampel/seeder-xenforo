<?php namespace Tests\Fixtures;

/**
 * Stands in for another add-on's extension of XenForo's registration service: notes each member
 * it saves, with the request the registration arrived on, in RegistrationsSeen::$seen, and mails
 * them twice - once sent and once queued.
 *
 * What it notes is kept on another class because this one cannot load until XenForo has made the
 * proxy it extends, which it does only when the service is first asked for.
 */
class RegistrationProbe extends XFCP_RegistrationProbe
{
	protected function _save()
	{
		$user = parent::_save();
		if (RegistrationsSeen::$fail)
		{
			throw new \RuntimeException('the probe failed');
		}

		$request = \XF::app()->request();
		RegistrationsSeen::$seen[] = [
			'user_id' => $user->user_id,
			'ip' => $request->getIp(),
			'user_agent' => $request->getServer('HTTP_USER_AGENT'),
			'method' => $request->getRequestMethod(),
			'time' => \XF::$time,
		];

		// as an add-on that mails a new member would, one way and the other
		$mailer = \XF::app()->mailer();
		$mailer->newMail()->setTo('probe@example.com')->setContent('Sent', '<p>Sent</p>')->send();
		$mailer->newMail()->setTo('probe@example.com')->setContent('Queued', '<p>Queued</p>')->queue();

		return $user;
	}
}
