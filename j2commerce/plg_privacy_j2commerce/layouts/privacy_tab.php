<?php
/**
 * Privacy tab body for the J2Commerce MyProfile view (shared by the com_j2commerce and
 * com_j2store default_privacy.php overrides). Pure rendering: all data is prepared by the caller.
 *
 * @package     J2Commerce Privacy Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 *
 * @var  array  $displayData {
 *     @type  bool    $consented       A valid consent record exists.
 *     @type  array   $records         [{date: string, order_id: ?string, source: 'checkout'|'account'}]
 *     @type  bool    $isGuest         Guest access via order token + e-mail.
 *     @type  bool    $showRequest     Show the privacy request block.
 *     @type  string  $requestUrl      com_privacy request form URL (logged-in users).
 *     @type  string  $contactEmail    Contact address for guests (mailto link).
 *     @type  int     $retentionYears  Retention period shown in the request description.
 * }
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$consented      = !empty($displayData['consented']);
$records        = $displayData['records'] ?? [];
$isGuest        = !empty($displayData['isGuest']);
$showRequest    = !empty($displayData['showRequest']);
$requestUrl     = (string) ($displayData['requestUrl'] ?? '');
$contactEmail   = (string) ($displayData['contactEmail'] ?? '');
$retentionYears = (int) ($displayData['retentionYears'] ?? 10);
?>
<div class="j2commerce-privacy">
    <h3 class="h5"><?php echo Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_TITLE'); ?></h3>
    <p><?php echo Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_INTRO'); ?></p>

    <section class="j2commerce-privacy-consent-status mb-4">
        <h4 class="h6"><?php echo Text::_('PLG_PRIVACY_J2COMMERCE_CONSENT_STATUS'); ?></h4>
        <?php if ($consented) : ?>
            <p class="text-success mb-2" data-consent-status="granted"><?php echo Text::_('PLG_PRIVACY_J2COMMERCE_CONSENT_ACTIVE'); ?></p>
            <table class="table table-sm">
                <caption class="visually-hidden"><?php echo Text::_('PLG_PRIVACY_J2COMMERCE_CONSENT_HISTORY'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('PLG_PRIVACY_J2COMMERCE_CONSENT_DATE'); ?></th>
                        <th scope="col"><?php echo Text::_('PLG_PRIVACY_J2COMMERCE_CONSENT_SOURCE'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record) : ?>
                        <tr>
                            <td><?php echo $escape($record['date'] ?? ''); ?></td>
                            <td>
                                <?php if (($record['source'] ?? '') === 'checkout') : ?>
                                    <?php echo Text::sprintf('PLG_PRIVACY_J2COMMERCE_CONSENT_SOURCE_CHECKOUT', $escape($record['order_id'] ?? '')); ?>
                                <?php else : ?>
                                    <?php echo Text::_('PLG_PRIVACY_J2COMMERCE_CONSENT_SOURCE_ACCOUNT'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="text-body-secondary" data-consent-status="none"><?php echo Text::_('PLG_PRIVACY_J2COMMERCE_NO_CONSENT_RECORDED'); ?></p>
        <?php endif; ?>
    </section>

    <?php if ($showRequest) : ?>
        <section class="j2commerce-privacy-request">
            <h4 class="h6"><?php echo Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_REQUEST_TITLE'); ?></h4>
            <?php if (!$isGuest && $requestUrl !== '') : ?>
                <p><?php echo Text::sprintf('PLG_PRIVACY_J2COMMERCE_MYPROFILE_REQUEST_DESC', $retentionYears); ?></p>
                <a class="btn btn-outline-primary" data-privacy-request="form" href="<?php echo $escape($requestUrl); ?>">
                    <?php echo Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_REQUEST_BTN'); ?>
                </a>
            <?php elseif ($contactEmail !== '') : ?>
                <p><?php echo Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_GUEST_REQUEST_DESC'); ?></p>
                <a class="btn btn-outline-primary" data-privacy-request="mailto"
                   href="mailto:<?php echo $escape($contactEmail); ?>?subject=<?php echo rawurlencode(Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_REQUEST_TITLE')); ?>">
                    <?php echo Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_GUEST_REQUEST_BTN'); ?>
                </a>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
