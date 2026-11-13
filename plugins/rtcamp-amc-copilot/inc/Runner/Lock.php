<?php
/**
 * A named lock stored in `amc_locks`.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Runner;

use AMC\Storage\LocksRepository;

/**
 * Class Lock
 *
 * Usage:
 *
 *   $lock = Lock::for_check( 'updates.core', $blog_id );
 *   if ( ! $lock->acquire() ) {
 *       return; // Someone else is running it.
 *   }
 *   try {
 *       // ... work ...
 *   } finally {
 *       $lock->release();
 *   }
 *
 * Locks expire after TTL seconds, so a crashed PHP process never blocks a
 * check for more than 10 minutes. Other lanes (Retention, Digest, Generator)
 * use Lock::named( 'retention' ) etc. for their own jobs.
 *
 * @since 0.1.0
 */
final class Lock {

	/**
	 * Default time to live: 10 minutes.
	 */
	public const TTL = 600;

	/**
	 * Unique token for this holder.
	 *
	 * @var string
	 */
	private string $owner;

	/**
	 * Whether this instance holds the lock.
	 *
	 * @var bool
	 */
	private bool $held = false;

	/**
	 * Constructor.
	 *
	 * @param string               $name    Lock name (<= 191 chars).
	 * @param int                  $ttl     Seconds before the lock expires.
	 * @param LocksRepository|null $locks   Repository (test seam).
	 */
	public function __construct(
		private readonly string $name,
		private readonly int $ttl = self::TTL,
		private ?LocksRepository $locks = null
	) {
		$this->locks ??= new LocksRepository();
		$this->owner   = wp_generate_uuid4();
	}

	/**
	 * Lock for one check on one site.
	 *
	 * @param string $check_id Check id.
	 * @param int    $blog_id  Site.
	 *
	 * @return static
	 */
	public static function for_check( string $check_id, int $blog_id ): self {
		return new self( "check:{$blog_id}:{$check_id}" );
	}

	/**
	 * Lock for any other job.
	 *
	 * @param string $job Job name, e.g. 'retention', 'digest'.
	 * @param int    $ttl Seconds.
	 *
	 * @return static
	 */
	public static function named( string $job, int $ttl = self::TTL ): self {
		return new self( "job:{$job}", $ttl );
	}

	/**
	 * Try to take the lock.
	 *
	 * @return bool True when this instance now holds it.
	 */
	public function acquire(): bool {
		$this->held = $this->locks->acquire( $this->name, $this->owner, $this->ttl );

		return $this->held;
	}

	/**
	 * Release the lock if held.
	 */
	public function release(): void {
		if ( $this->held ) {
			$this->locks->release( $this->name, $this->owner );
			$this->held = false;
		}
	}

	/**
	 * Lock name.
	 */
	public function name(): string {
		return $this->name;
	}
}
