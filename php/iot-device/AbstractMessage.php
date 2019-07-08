<?php

namespace Cabinet\Protocol;

class AbstractMessage
{
	const LITTLE_ENDIAN = 'little';
	const BIG_ENDIAN = 'big';

	/** @var \Cabinet\Protocol\IProtocolHandler $handler */
	protected $handler;

	protected $incID;
	protected $modemID;
	protected $data;
	protected $received;
	protected $eventTime;
	protected $iterator;

	protected $bin;

	protected $endianness;
	protected $log = true;

	protected $appendedIncs = array();

	public function __construct( array $inc, $log=true, $endiannes=self::BIG_ENDIAN )
	{
		$this->incID = $inc['id'];
		$this->modemID = $inc['modem_id'];
		$this->data = $inc['data'];
		$this->received = $inc['received'];
		$this->eventTime = $inc['event_time'];
		$this->iterator = $inc['iterator'];

		$this->bin = $this->hex2bin( $this->data );
		$this->log = $log;
		$this->endianness = $endiannes;
	}

	public function enableLogger()
	{
		$this->log = true;
	}

	public function disableLogger()
	{
		$this->log = false;
	}

	public function bytes( $withIterator=true )
	{
		return $withIterator ? (integer) floor( strlen( $this->bin ) / 8 ) :
			(integer) floor( ( strlen( $this->bin ) - 4 ) / 8 );
	}

	public function bits( $withIterator=true )
	{
		return $withIterator ? strlen( $this->bin ) : strlen( $this->bin ) - 4;
	}

	public function getIterator()
	{
		return $this->iterator;
	}

	public function getModemID()
	{
		return $this->modemID;
	}

	public function getEventTime()
	{
		return $this->eventTime;
	}

	public function getIncID()
	{
		return $this->incID;
	}

	public function getData()
	{
		return $this->data;
	}

	public function getValueFromBits( $start, $length, $complement=false )
	{
		if ( !$complement )
			return $this->bin2dec( $this->getBinSlice( $start, $length ) );
		else
		{
			$bin = $this->getBinSlice( $start, $length );
			$num = bindec( $bin ) & 0xFFFF; // only use bottom 16 bits
			if ( 0x8000 & $num )
				$num = - ( 0x010000 - $num );

			return $num;
		}
	}

	public function getBinSlice( $start, $length )
	{
		if ( $this->endianness == self::BIG_ENDIAN || $length <= 8 )
			return substr( $this->bin, $start, $length );
		else
		{
			if ( $length % 8 != 0 )
				$targetLength = 8 * ( intval( floor( $length / 8 ) ) + 1 );
			else
				$targetLength = $length;

			if ( $start % 8 != 0 )
			{
				$targetStart = $start - $start % 8;
				$targetLength = $length + $start % 8;
			}
			else
				$targetStart = $start;

			$slice = substr( $this->bin, $targetStart, $targetLength );
			$bytes = str_split( $slice, 8 );

			$result = '';
			foreach( array_reverse( $bytes ) as $byte )
				$result .= $byte;

			if ( $start > 0 )
				return substr( $result, 0, $targetLength );

			if ( $length % 8 != 0 )
				return substr( $result, strlen( $result ) - $length, $length );

			return $result;
		}
	}

	public function getHexSlice( $start, $length )
	{
		$hex = base_convert( $this->getBinSlice( $start, $length ), 2, 16 );
		$exLength = ceil( $length / 4 );

		return str_pad( $hex, $exLength, '0', STR_PAD_LEFT );
	}

	public function bin2dec( $data )
	{
		return base_convert( $data, 2, 10 );
	}

	public function hex2bin( $data )
	{
		if ( $data == '' )
			return false;

		$bin = '';
		$dataArr = str_split( $data, 2 );

		foreach( $dataArr as $val )
		{
			$binary = base_convert( $val, 16, 2 );
			$bin .= str_pad( $binary, 8, '0', STR_PAD_LEFT );
		}

		return $bin;
	}

	public function bin2hex( $data )
	{
		if ( $data == '' )
			return '0';

		$expectedChars = ceil( strlen( $data ) / 4 );
		$hex = strtoupper( base_convert( $data, 2, 16 ) );
		$hex = str_pad( $hex, $expectedChars, '0', STR_PAD_LEFT );

		return $hex;
	}

	public function getBin()
	{
		return $this->bin;
	}

	public function getNextIterator( $plus = 1 )
	{
		$iterator = $this->getIterator() + $plus;
		if ( $iterator < 0 )
		{
			$iterator += pow( 2, 4 );
			return $iterator;
		}

		return $iterator % pow( 2, 4 );
	}

	public function append( $msg, $shift )
	{
		/** @var AbstractMessage $msg */
		$msgData = $msg->getBinSlice( $shift, $msg->bits() - $shift );
		$this->bin .= $msgData;
		$this->data .= $this->bin2hex( $msgData );

		$this->appendedIncs[] = $msg->getIncID();

		return true;
	}

	public function getPacketsIDs()
	{
		if ( sizeof( $this->appendedIncs ) > 0 )
		{
			$ar = array( $this->getIncID() );
			foreach( $this->appendedIncs as $appendedInc )
				$ar[] = $appendedInc;

			return $ar;
		}

		return $this->getIncID();
	}

	public function parse( $bitsConfig, $complementValues=array() )
	{
		$result = array();
		$bitsConfig['iterator'] = array( 0, 4 );

		foreach( $bitsConfig as $name => $config )
		{
			if ( !in_array( $name, $complementValues ) )
				$result[$name] = $this->getValueFromBits( $config[0], $config[1] );
			else
				$result[$name] = $this->getValueFromBits( $config[0], $config[1], true );
		}

		return $result;
	}

	public function getVoltage( $voltageMask )
	{
		return 2 + ( $voltageMask >> 7 ) + ( ( $voltageMask & 0x7f ) / 100 );
	}
}