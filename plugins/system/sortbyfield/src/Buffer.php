<?php
/**
 * @project   sortbyfield
 * @copyright Copyright (c) 2021-2023 Nicholas K. Dionysopoulos
 * @license   GPLv3
 */

namespace Dionysopoulos\Plugin\System\SortByField;

defined('_JEXEC') || die;

/**
 * Registers a plgSystemSortbyfieldsBuffer:// stream wrapper
 *
 * @since 1.0.0
 */
#[\AllowDynamicProperties]
class Buffer
{
	/**
	 * Buffer hash
	 *
	 * @var    array
	 * @since   1.0.0
	 */
	public static array $buffers = [];

	public static ?bool $canRegisterWrapper = null;

	/**
	 * Stream position
	 *
	 * @var    integer
	 * @since   1.0.0
	 */
	public int $position = 0;

	/**
	 * Buffer name
	 *
	 * @var    string|null
	 * @since   1.0.0
	 */
	public ?string $name = null;

	/**
	 * Should I register the plgSystemSortbyfieldsBuffer:// stream wrapper
	 *
	 * @return  bool  True if the stream wrapper can be registered
	 *
	 * @since   1.0.0
	 */
	public static function canRegisterWrapper(): bool
	{
		if (is_null(static::$canRegisterWrapper))
		{
			static::$canRegisterWrapper = false;

			// Maybe the host has disabled registering stream wrappers altogether?
			if (!function_exists('stream_wrapper_register'))
			{
				return false;
			}

			// Check for Suhosin
			if (function_exists('extension_loaded'))
			{
				$hasSuhosin = extension_loaded('suhosin');
			}
			else
			{
				$hasSuhosin = -1; // Can't detect
			}

			if ($hasSuhosin !== true)
			{
				$hasSuhosin = defined('SUHOSIN_PATCH') ? true : -1;
			}

			if ($hasSuhosin === -1)
			{
				if (function_exists('ini_get'))
				{
					$hasSuhosin = false;

					$maxIdLength = ini_get('suhosin.session.max_id_length');

					if ($maxIdLength !== false)
					{
						$hasSuhosin = ini_get('suhosin.session.max_id_length') !== '';
					}
				}
			}

			// If we can't detect whether Suhosin is installed we won't proceed to prevent a White Screen of Death
			if ($hasSuhosin === -1)
			{
				return false;
			}

			// If Suhosin is installed but ini_get is not available we won't proceed to prevent a WSoD
			if ($hasSuhosin && !function_exists('ini_get'))
			{
				return false;
			}

			// If Suhosin is installed check if fof:// is whitelisted
			if ($hasSuhosin)
			{
				$whiteList = ini_get('suhosin.executor.include.whitelist');

				// Nothing in the whitelist? I can't go on, sorry.
				if (empty($whiteList))
				{
					return false;
				}

				$whiteList = explode(',', $whiteList);
				$whiteList = array_map(
					function ($x) {
						return trim($x);
					}, $whiteList
				);

				if (!in_array('fof://', $whiteList))
				{
					return false;
				}
			}

			static::$canRegisterWrapper = true;
		}

		return static::$canRegisterWrapper;
	}

	/**
	 * Function to open file or url
	 *
	 * @param   string       $path         The URL that was passed
	 * @param   string       $mode         Mode used to open the file @see fopen
	 * @param   integer      $options      Flags used by the API, may be STREAM_USE_PATH and
	 *                                     STREAM_REPORT_ERRORS
	 * @param   string|null  $opened_path  Full path of the resource. Used with STREAM_USE_PATH option
	 *
	 * @return  boolean
	 * @since        1.0.0
	 *
	 * @see          streamWrapper::stream_open
	 * @noinspection PhpUnused
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
	{
		$url            = parse_url($path);
		$this->name     = $url['host'] . ($url['path'] ?? '');
		$this->position = 0;

		if (!isset(static::$buffers[$this->name]))
		{
			static::$buffers[$this->name] = null;
		}

		return true;
	}

	/**
	 * @noinspection PhpUnused
	 * @noinspection PhpUnusedParameterInspection
	 *
	 * @since        1.0.0
	 */
	public function stream_set_option($option, $arg1 = null, $arg2 = null): bool
	{
		return false;
	}

	public function unlink(string $path): void
	{
		$url  = parse_url($path);
		$name = $url['host'];

		if (isset(static::$buffers[$name]))
		{
			unset (static::$buffers[$name]);
		}
	}

	/**
	 * @noinspection PhpUnused
	 * @since        1.0.0
	 */
	public function stream_stat(): array
	{
		return [
			'dev'     => 0,
			'ino'     => 0,
			'mode'    => 0644,
			'nlink'   => 0,
			'uid'     => 0,
			'gid'     => 0,
			'rdev'    => 0,
			'size'    => strlen(static::$buffers[$this->name]),
			'atime'   => 0,
			'mtime'   => 0,
			'ctime'   => 0,
			'blksize' => -1,
			'blocks'  => -1,
		];
	}

	/**
	 * Read stream
	 *
	 * @param   integer  $count  How many bytes of data from the current position should be returned.
	 *
	 * @return  string    The data from the stream up to the specified number of bytes (all data if
	 *                   the total number of bytes in the stream is less than $count. Null if
	 *                   the stream is empty.
	 *
	 * @since        1.0.0
	 * @noinspection PhpUnused
	 * @see          streamWrapper::stream_read
	 */
	public function stream_read(int $count): string
	{
		$ret            = substr(static::$buffers[$this->name], $this->position, $count);
		$this->position += strlen($ret);

		return $ret;
	}

	/**
	 * Write stream
	 *
	 * @param   string  $data  The data to write to the stream.
	 *
	 * @return  integer
	 *
	 * @since        1.0.0
	 * @noinspection PhpUnused
	 * @see          streamWrapper::stream_write
	 */
	public function stream_write(string $data): int
	{
		$left                         = substr(static::$buffers[$this->name] ?? '', 0, $this->position);
		$right                        = substr(static::$buffers[$this->name] ?? '', $this->position + strlen($data));
		static::$buffers[$this->name] = $left . $data . $right;
		$this->position               += strlen($data);

		return strlen($data);
	}

	/**
	 * Function to get the current position of the stream
	 *
	 * @return  integer
	 *
	 * @since        1.0.0
	 * @noinspection PhpUnused
	 * @see          streamWrapper::stream_tell
	 */
	public function stream_tell(): int
	{
		return $this->position;
	}

	/**
	 * Function to test for end of file pointer
	 *
	 * @return  boolean  True if the pointer is at the end of the stream
	 *
	 * @since        1.0.0
	 * @noinspection PhpUnused
	 * @see          streamWrapper::stream_eof
	 */
	public function stream_eof(): bool
	{
		return $this->position >= strlen(static::$buffers[$this->name]);
	}

	/**
	 * The read write position updates in response to $offset and $whence
	 *
	 * @param   integer  $offset  The offset in bytes
	 * @param   integer  $whence  Position the offset is added to
	 *                            Options are SEEK_SET, SEEK_CUR, and SEEK_END
	 *
	 * @return  boolean  True if updated
	 *
	 * @since        1.0.0
	 * @noinspection PhpUnused
	 * @see          streamWrapper::stream_seek
	 */
	public function stream_seek(int $offset, int $whence): bool
	{
		switch ($whence)
		{
			case SEEK_SET:
				if ($offset < strlen(static::$buffers[$this->name]) && $offset >= 0)
				{
					$this->position = $offset;

					return true;
				}

				return false;

			case SEEK_CUR:
				if ($offset >= 0)
				{
					$this->position += $offset;

					return true;
				}

				return false;

			case SEEK_END:
				if (strlen(static::$buffers[$this->name]) + $offset >= 0)
				{
					$this->position = strlen(static::$buffers[$this->name]) + $offset;

					return true;
				}

				return false;

			default:
				return false;
		}
	}
}

if (Buffer::canRegisterWrapper())
{
	stream_wrapper_register('plgSystemSortbyfieldsBuffer', Buffer::class);
}
