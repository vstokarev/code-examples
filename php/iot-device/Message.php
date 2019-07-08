<?php

namespace Cabinet\Protocol\Water5;

class Message extends \Cabinet\Protocol\AbstractMessage
{
	const TYPE_DAILY    = 'daily';
	const TYPE_WEEKLY   = 'weekly';
	const TYPE_INFO     = 'info';
	const TYPE_MANUAL   = 'manual';
	const TYPE_RESET    = 'reset';
	const TYPE_INVALID  = 'invalid';
	const TYPE_EXTINFO  = 'extinfo';

	protected $counterLengths = array(
		'day' => 15,
		'week' => 27,
		'info' => 32
	);

	public function __construct( $inc, Handler $handler, $log=true )
	{
		parent::__construct( $inc, $log, self::LITTLE_ENDIAN );
		$this->handler = $handler;
	}

	public function getType()
	{
		$dailyBit = $this->getBinSlice( 7, 1 );
		if ( !$dailyBit )
			return self::TYPE_DAILY;
		else
		{
			switch( $this->getValueFromBits( 0, 4 ) )
			{
				case 7:
					return self::TYPE_WEEKLY;
				case 6:
					return self::TYPE_INFO;
				case 5:
					return self::TYPE_MANUAL;
				case 4:
					return self::TYPE_RESET;
				case 8:
					return self::TYPE_EXTINFO;
				default:
					return self::TYPE_INVALID;
			}
		}
	}

	public function getCounterLength( $mode )
	{
		return isset( $this->counterLengths[$mode] ) ? $this->counterLengths[$mode] : 16;
	}

	public function extractDailyMessage()
	{
		return array( 'total' => $this->extractCounter(), 'rates' => $this->extractHourRates() );
	}

	public function extractWeeklyMessage()
	{
		$result = array(
			'total' => $this->extractCounter(),
			'rates' => $this->extractDailyRates()
		);

		$result['voltage'] = $this->getValueFromBits( 56, 8 );

		if ( $this->log )
			$this->handler->getLogger()->setPacketData( array( 'voltage' => array( 56, 8, $result['voltage'] ) ) );

		return $result;
	}

	public function extractInfo()
	{
		$result = array(
			'total' => $this->extractCounter(),
			'messcounter' => $this->getValueFromBits( 40, 16 ),
			'maxconsumption' => $this->getValueFromBits( 58, 6 ),
			'power_level' => $this->getValueFromBits( 56, 2 )
		);

		if ( $this->log )
		{
			$this->handler->getLogger()->setPacketData( array( 'messcounter' => array( 40, 16, $result['messcounter'] ) ) );
			$this->handler->getLogger()->setPacketData( array( 'maxconsumption' => array( 58, 6, $result['maxconsumption'] ) ) );
			$this->handler->getLogger()->setPacketData( array( 'power_level' => array( 56, 2, $result['power_level'] ) ) );
		}

		return $result;
	}

	public function extractExtendedInfo()
	{
		$result = [
			'hardware_rev' => $this->getValueFromBits( 8, 8 ),
			'software_rev' => $this->getValueFromBits( 16, 8 ),
			'crc' => $this->getValueFromBits( 24, 8 ),
			'temperature' => $this->getValueFromBits( 32, 8 ),
			'voltage' => $this->getValueFromBits( 40, 8 ),
			'power_level' => $this->getValueFromBits( 48, 8 ),
			'maxconsumption' => $this->getValueFromBits( 56, 8 )
		];

		if ( $this->log )
		{
			$this->handler->getLogger()->setPacketData( [ 'hardware_rev' => [ 8, 8, $result['hardware_rev'] ] ] );
			$this->handler->getLogger()->setPacketData( [ 'software_rev' => [ 16, 8, $result['software_rev'] ] ] );
			$this->handler->getLogger()->setPacketData( [ 'crc' => [ 24, 8, $result['crc'] ] ] );
			$this->handler->getLogger()->setPacketData( [ 'temperature' => [ 32, 8, $result['temperature'] ] ] );
			$this->handler->getLogger()->setPacketData( [ 'voltage' => [ 40, 8, $result['voltage'] ] ] );
			$this->handler->getLogger()->setPacketData( [ 'power_level' => [ 48, 8, $result['power_level'] ] ] );
			$this->handler->getLogger()->setPacketData( [ 'maxconsumption' => [ 56, 8, $result['maxconsumption'] ] ] );
		}

		return $result;
	}

	public function extractManual()
	{
		$result = array(
			'total' => $this->extractCounter(),
			'command' => $this->getValueFromBits( 40, 8 )
		);

		// In early versions of water5 all spare bytes were filled with AA
		// Later temperature and voltage were places in the last two byes

		$temperature = $this->getValueFromBits( 48, 8 );
		if ( $temperature != 170 )  // AA
		{
			$result['temperature'] = $temperature;
			$result['voltage'] = $this->getValueFromBits( 56, 8 );
		}

		if ( $this->log )
			$this->handler->getLogger()->setPacketData( array( 'command' => array( 40, 8, $result['command'] ) ) );

		return $result;
	}

	public function extractReset()
	{
		return array( 'total' => $this->extractCounter() );
	}

	public function extractCounter()
	{
		switch( $this->getType() )
		{
			case self::TYPE_DAILY:
				$value = $this->getValueFromBits( 0, 16 );
				$value >>= 1;
				//$value *= 10;

				if ( $this->log )
					$this->handler->getLogger()->setPacketData( [ 'water' => [ 0, 16, $value, $this->getBinSlice( 0, 16 ) ] ] );
			break;
			case self::TYPE_WEEKLY:
				$binLSBValue = $this->getBinSlice( 8, 24 );
				$binDailyRates = $this->getBinSlice( 32, 24 );

				$binValue = substr( $binDailyRates, -3 ) . $binLSBValue;
				$value = base_convert( $binValue, 2, 10 );

				if ( $this->log )
					$this->handler->getLogger()->setPacketData( array( 'water' => array( 8, 27, $value, $binValue ) ) );
			break;
			default:
				$value = $this->getValueFromBits( 8, 32 );

				if ( $this->log )
					$this->handler->getLogger()->setPacketData( [ 'water' => [ 8, 32, $value, $this->getBinSlice( 8, 32 ) ] ] );
		}

		return $value;
	}

	public function getMultiplier()
	{
		$type = $this->getType();

		if ( $type == self::TYPE_DAILY )
			return 10;

		if ( $type == self::TYPE_WEEKLY && $this->getValueFromBits( 4, 4 ) == 1 )
			return 10;

		return 1;
	}

	public function extractCounterMultiplied()
	{
		$multiplier = $this->getMultiplier();
		return $multiplier > 1 ? $this->extractCounter() * $multiplier : floor( $this->extractCounter() / 10 ) * 10;
	}

	public function getOverflowedValue( $mode, $ratio, $counter )
	{
		$overflowPoint = ( 0x1 << $this->getCounterLength( $mode ) ) * $ratio;
		$overflowPoint *= $this->getMultiplier();

		return $overflowPoint + $counter;
	}

	public function extractHourRates()
	{
		$ratesBinary = $this->getBinSlice( 16, 48 );

		$rates = [];
		foreach( range( 1, 24 ) as $hour )
		{
			$pos = ( $hour - 1 ) * 2;
			$binRate = substr( $ratesBinary, $pos, 2 );
			$rate = base_convert( $binRate, 2, 10 );

			if ( $this->log )
				$this->handler->getLogger()->setServiceData( 'h'.$hour, array( 16 + $pos, 2, $rate, $binRate ) );

			// Rates are sent in reverse order (-1 hour -2 hours and so on), but we will fill values
			// in straight order, so save rates accordingly
			$rates[25-$hour] = $rate;
		}

		return $rates;
	}

	public function extractDailyRates()
	{
		$ratesBinary = $this->getBinSlice( 32, 24 );

		$rates = [];
		foreach( range( 1, 7 ) as $day )
		{
			$pos = ( $day - 1 ) * 3;
			$binRate = substr( $ratesBinary, $pos, 3 );
			$rate = base_convert( $binRate, 2, 10 );

			if ( $this->log )
				$this->handler->getLogger()->setServiceData( 'd'.$day, array( 32 + $pos, 3, $rate, $binRate ) );

			$rates[7-$day] = $rate;
		}

		return $rates;
	}

	public function calculateOverflowRatios( $fullTotal )
	{
		$ratios = [];
		foreach( [ 'day', 'week' ] as $mode )
		{
			$overflowPoint = ( 0x1 << $this->getCounterLength( $mode ) ) * 10;
			$ratios[$mode] = intval( floor( $fullTotal / $overflowPoint ) );
		}

		return $ratios;
	}
}