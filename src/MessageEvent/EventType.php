<?php

declare(strict_types=1);

namespace Hampel\SparkPost\MessageEvent;

/**
 * The event types SparkPost reports on a message.
 *
 * No groupings here on purpose. Which of these counts as "a bounce worth acting on" is
 * the consuming application's policy, not SparkPost's, and every application has drawn
 * that line differently.
 */
enum EventType: string
{
    case Bounce = 'bounce';
    case Delay = 'delay';
    case Delivery = 'delivery';
    case Injection = 'injection';
    case GenerationFailure = 'generation_failure';
    case GenerationRejection = 'generation_rejection';
    case ListUnsubscribe = 'list_unsubscribe';
    case LinkUnsubscribe = 'link_unsubscribe';
    case OutOfBand = 'out_of_band';
    case PolicyRejection = 'policy_rejection';
    case SpamComplaint = 'spam_complaint';
    case Open = 'open';
    case InitialOpen = 'initial_open';
    case Click = 'click';
    case AmpClick = 'amp_click';
    case AmpOpen = 'amp_open';
    case AmpInitialOpen = 'amp_initial_open';
}
