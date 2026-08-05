<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

/**
 * Ein fehlerhafter Eintrag in config/genetik.php. Er faellt sofort auf, statt
 * still zu einer falschen Verteilung zu werden — eine geratene Superform oder
 * ein verschluckter Tierschutzhinweis waere schlimmer als ein Abbruch.
 */
final class GeneticsConfigurationException extends GeneticsException {}
