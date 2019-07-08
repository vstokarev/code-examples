<?php

namespace Cabinet\Protocol\Water5;

use Cabinet\Models\Packet;
use Cabinet\Protocol\AbstractHandler;
use Cabinet\Protocol\HandlerLogger;
use Cabinet\Protocol\IProtocolHandler;
use Cabinet\Protocol\Queue;
use Zend\Db\Sql\Where;

/*************************************
 * Class Handler Water version 5 protocol handler
 * @package Cabinet\Protocol\Water5
 *
 * Protocol sends messages once a day and once a week
 *
 * The type of message is determined by the message's length:
 * 8 bytes. Daily message. Consists of current totals and hours consumptions
 *
 * 7 bytes. Weekly summary. Contains current totals, daily consumptions and
 * current battery voltage
 *
 * 6 bytes. Info message. It is sent once per month and contains full totals
 * and number of messages, sent by the modem so far
 *
 * 5 bytes. Manual message. Contains command id. Used mainly for calibration
 *
 * 4 bytes. Cold reset. Sent after modem reset or after it has been enabled
 *
 * This protocol does not contain iterator in payload
 *************************************/

class Handler extends AbstractHandler implements IProtocolHandler
{
	// ID of the protocol in database
	const PROTOCOL_ID = 10;

	// Config ID
	const DEVICE_FW = 20;

	public function __construct()
	{
		$this->queue = new Queue( $this );
	}

	public function getProtocolID()
	{
		return self::PROTOCOL_ID;
	}

	/**
	 * @return \Cabinet\Protocol\Queue
	 */
	public function getQueue()
	{
		return $this->queue;
	}

	/**
	 * @return Presenter
	 */
	public function getPresenter()
	{
		return $this->presenter;
	}

	public function initPresenter()
	{
		$this->presenter = new Presenter( $this );
	}

	public function getMessage( Packet $packet )
	{
		return new Message( $packet->getDataArray(), $this );
	}

	public function processQueue()
	{
		$this->logger = new HandlerLogger();
		$this->logger->setServiceLocator( $this->serviceLocator );
		$this->initPresenter();

		if ( $this->queue->getQueueSize() == 0 )
		{
			echo 'Queue is empty' . "\n";
			return false;
		}

		$queueSize = $this->queue->getQueueSize();
		$msgNum = 0;

		$modems = $this->queue->getModemsList();
		foreach( $modems as $modem )
		{
			$initResult = $this->getPresenter()->initWithModem( $modem );

			if ( $initResult != Presenter::INIT_OK && $initResult != Presenter::INIT_NOT_MODERATED )
				$this->getPresenter()->registerDeviceAndRegistrator( $modem );

			while( !is_null( $inc = $this->queue->getNextInc( $modem ) ) )
			{
				$this->logger->init( $inc['id'] );
				$this->logger->log( '[ ' . ++$msgNum . ' / ' . $queueSize . ' ] ' . 'Modem: ' . $modem );
				$this->logger->log( 'Message #' . $inc['id'] . ', data: ' . $inc['data'], 2 );

				$msg = new Message( $inc, $this );
				$msgType = $msg->getType();

				$this->logger->setMessageBinary( $msg->getBin() );
				$this->logger->log( 'Type: ' . $msgType );
				$this->logger->setPacketType( $msgType );

				if ( $msgType == Message::TYPE_INVALID )
				{
					$this->markPacketsAsProcessed( $msg->getIncID() );

					$this->logger->log( 'Invalid message' );
					$this->logger->save();

					continue;
				}

				if ( $initResult == Presenter::INIT_NOT_MODERATED && in_array( $msgType, array( Message::TYPE_DAILY, Message::TYPE_WEEKLY ) ) )
					continue;

				switch( $msgType )
				{
					case Message::TYPE_DAILY:
						$result = $this->processDaily( $msg );
						break;
					case Message::TYPE_WEEKLY:
						$result = $this->processWeekly( $msg );
						break;
					case Message::TYPE_INFO:
						$result = $this->processInfo( $msg );
						break;
					case Message::TYPE_MANUAL:
						$result = $this->processManual( $msg );
						break;
					case Message::TYPE_RESET:
						$result = $this->processReset( $msg );
						break;
					case Message::TYPE_EXTINFO:
						$result = $this->processExtendedInfo( $msg );
						break;
					default:
						$result = false;
				}

				if ( $result === true )
					$this->markPacketsAsProcessed( $msg->getIncID() );
				elseif( $result === 'P' )
					$this->markPacketsAsProcessed( $msg->getIncID(), 'P' );

				$this->logger->save();
			}
		}
	}

	public function processDaily( Message $msg )
	{
		$prePreviousMessage = null;

		// Get previous message
		$previousMessage = $this->_getPreviousMessage( $msg );
		if ( $previousMessage instanceof Message )
		{
			$previousTotal = $this->getFullTotal( $previousMessage );
			$this->logger->log( 'Previous total: ' . $previousTotal );
		}
		else
		{
			$this->logger->log( 'Previous total was not found' );
			$previousTotal = null;
		}

		if ( is_null( $previousTotal ) )
		{
			$prePreviousMessage = $this->_getPrePreviousMessage( $msg );
			if ( $prePreviousMessage instanceof Message )
			{
				$prePreviousTotal = $this->getFullTotal( $prePreviousMessage );
				$this->logger->log( 'Pre-previous total: ' . $prePreviousTotal );
			}
			else
			{
				$prePreviousTotal = null;
				$this->logger->log( 'Even pre-previous message was not found' );
			}
		}

		if ( !is_null( $previousTotal ) && $this->_isOverflow( $msg, $previousMessage ) )
		{
			$this->logger->log( 'Overflow detected' );
			if ( !$this->getPresenter()->checkIfOverflowIsRegistered( $msg->getIncID(), 'day' ) )
				$this->getPresenter()->registerOverflow( $msg->getEventTime(), $msg->getIncID(), 'day' );
			else
				$this->logger->log( 'This overflow has already been registered before' );
		}

		if ( isset( $prePreviousTotal ) && !is_null( $prePreviousTotal ) && $this->_isOverflow( $msg, $prePreviousMessage ) )
		{
			$this->logger->log( 'Overflow detected (based on pre-previous message)' );
			if ( !$this->getPresenter()->checkIfOverflowIsRegistered( $msg->getIncID(), 'day' ) )
				$this->getPresenter()->registerOverflow( $msg->getEventTime(), $msg->getIncID(), 'day' );
			else
				$this->logger->log( 'This overflow has already been registered before' );
		}

		if ( is_null( $previousTotal ) && !isset( $prePreviousTotal ) )
		{
			// If no previous message found there is still possibility, that there was overflow during missed period
			// We need to make another check for this be getting the last available packet for this modem
			$lastModemMessage = $this->getLastModemMessage( $msg, Message::TYPE_DAILY );
			if ( $lastModemMessage )
			{
				if ( $this->_isOverflow( $msg, $lastModemMessage ) )
				{
					$this->logger->log( 'Overflow detected (based on the last message from modem)' );
					if ( !$this->getPresenter()->checkIfOverflowIsRegistered( $msg->getIncID(), 'day' ) )
						$this->getPresenter()->registerOverflow( $msg->getEventTime(), $msg->getIncID(), 'day' );
					else
						$this->logger->log( 'This overflow has already been registered before' );
				}
			}
		}

		$newTotal = $this->getFullTotal( $msg, 'day' );
		$this->getLogger()->log( 'Current total: ' . $newTotal );

		$fillTimestamp = $this->getRoundedHour( $msg->getEventTime() );
		if ( !is_null( $previousTotal ) )
		{
			// Ok, got previous and current values. Calculate consumption
			$consumption = $newTotal - $previousTotal;
			$this->logger->log( 'Consumption: ' . $consumption, 2 );

			if ( $consumption < 0 )
			{
				$newTotal = $previousTotal;
				$consumption = 0;
			}

			$hourly = $this->_restoreConsumptionFromRates( $msg->extractHourRates(), $consumption, 'day' );

			$this->_saveHourly( $msg, $newTotal, $hourly );

			$fillTimestamp = $this->getRoundedHour( $msg->getEventTime() ) - 86400;
		}
		elseif ( isset( $prePreviousTotal ) )
		{
			$consumption = $newTotal - $prePreviousTotal;
			$this->logger->log( 'Consumption: ' . $consumption . ' (based on pre-previous message)', 2 );
		}

		$this->_saveTotal( $msg, $newTotal );
		$this->_makeFillJob( $this->getPresenter()->getSingleRegistraror(), $fillTimestamp );

		//return true;
		return is_null( $previousMessage ) && is_null( $prePreviousMessage ) ? 'P' : true;
	}

	public function processWeekly( Message $msg )
	{
		$weeklyData = $msg->extractWeeklyMessage();

		$voltage = $msg->getVoltage( $weeklyData['voltage'] );
		$this->getLogger()->log( 'Voltage: ' . $voltage );
		$this->_updateModemData( $msg->getModemID(), $msg->getEventTime(), $voltage );

		// Weekly message gives us a unique chance to restore lost values for the days in the week passed
		// All daily messages which failed to restore hourly rates are marked with processed=P in database
		$unprocessedDaily = $this->_getPartialProcessedMessages( $msg );
		$previousWeekly = $this->_getPreviousMessage( $msg, 'week' );

		$newTotal = $this->getFullTotal( $msg, 'week' );

		if ( is_null( $previousWeekly ) )
		{
			$this->getLogger()->log( 'Previous total was not found, cannot check daily totals' );
			$this->getLogger()->log( 'Current total: ' . $newTotal, 2 );
			$this->_saveTotal( $msg, $newTotal );

			return true;
		}

		$previousTotal = $this->getFullTotal( $previousWeekly, 'week' );

		$this->getLogger()->log( 'Previous total: ' . $previousTotal );
		$this->getLogger()->log( 'Current total: ' . $newTotal );

		$consumption = $newTotal - $previousTotal;
		$this->getLogger()->log( 'Week consumption: ' . $consumption );

		// Save totals for the past week
		$weekly = $this->_restoreConsumptionFromRates( $weeklyData['rates'], $consumption, 'week' );
		$this->_saveWeekly( $msg, $newTotal, $weekly );

		if ( is_null( $unprocessedDaily ) )
		{
			$this->getLogger()->log( 'No unprocessed messages for the past week found, nothing to restore', 2 );
			$this->_saveTotal( $msg, $newTotal );

			return true;
		}

		foreach( $unprocessedDaily as $dayNum => $dailyMessage )
		{
			if ( !array_key_exists( $dayNum, $weekly ) )
				continue;

			$current = $this->getFullTotal( $dailyMessage, 'day' );

			$hourly = $this->_restoreConsumptionFromRates( $dailyMessage->extractHourRates(), $weekly[$dayNum] );
			$this->_saveHourly( $dailyMessage, $current, $hourly );

			$this->getLogger()->log( 'Restored hourly values for week day #' . $dayNum );
		}

		$this->getLogger()->log( '' );
		$this->_saveTotal( $msg, $newTotal );

		return true;
	}

	public function processInfo( Message $msg )
	{
		$infoData = $msg->extractInfo();

		$currentOverflowRatios = $msg->calculateOverflowRatios( $infoData['total'] );
		foreach( [ 'day', 'week' ] as $mode )
		{
			if ( $currentOverflowRatios[$mode] != $this->getPresenter()->getOverflowRatio( $msg->getEventTime(), $mode ) )
			{
				$this->getPresenter()->resetOverflow( $msg->getEventTime(), $infoData['total'], $msg->getIncID(),
					$currentOverflowRatios );
				break;
			}
		}

		//$newTotal = $this->getFullTotal( $msg );

		if ( $infoData['maxconsumption'] != 170 )
			$this->getLogger()->log( 'MAX consumption so far: ' . $infoData['maxconsumption'] . ' l/min' );

		$this->getLogger()->log( 'Counter value: ' . $infoData['total'] . ', extracted total value: '
		                         . $this->convertToRealValue( $infoData['total'] ) );
		$this->getLogger()->log( 'Messages counter value: ' . $infoData['messcounter'] );

		$realValue = $this->convertToRealValue( $infoData['total'], true );
		$this->getLogger()->log( 'Current counter value: ' . $realValue );

		$registrator = $this->getPresenter()->getSingleRegistraror();

		if ( $registrator->getLastValueTimestamp() < $msg->getEventTime() )
			$this->_updateRegistratorWithLatestValue( $registrator->getId(), $msg->getEventTime(), $realValue );

		return true;
	}
	
	public function processExtendedInfo( Message $msg )
	{
		$extInfoData = $msg->extractExtendedInfo();

		$voltage = $msg->getVoltage( $extInfoData['voltage'] );
		$this->getLogger()->log( 'Voltage: ' . $voltage );
		$this->getLogger()->log( 'Temperature: ' . $extInfoData['temperature'] );

		$this->_updateModemData( $msg->getModemID(), $msg->getEventTime(), $voltage, $extInfoData['temperature'],
			$extInfoData['software_rev'] );

		return true;
	}

	public function processManual( Message $msg )
	{
		$manualData = $msg->extractManual();

		$currentOverflowRatios = $msg->calculateOverflowRatios( $manualData['total'] );
		foreach( [ 'day', 'week' ] as $mode )
		{
			if ( $currentOverflowRatios[$mode] != $this->getPresenter()->getOverflowRatio( $msg->getEventTime(), $mode ) )
			{
				$this->getPresenter()->resetOverflow( $msg->getEventTime(), $manualData['total'], $msg->getIncID(),
					$currentOverflowRatios );
				break;
			}
		}

		if ( isset( $manualData['voltage'] ) )
		{
			$voltage = $msg->getVoltage( $manualData['voltage'] );
			$this->getLogger()->log( 'Voltage: ' . $voltage );
			$this->getLogger()->log( 'Temperature: ' . $manualData['temperature'] );

			$this->_updateModemData( $msg->getModemID(), $msg->getEventTime(), $voltage, $manualData['temperature'] );
		}

		if ( in_array( $manualData['command'], array( '5', '33' ) ) )
		{
			$this->getLogger()->log( 'Calibration value: ' . $manualData['total'] );
			$this->_saveTotal( $msg, $manualData['total'] );

			$this->getPresenter()->addModemToQueue( $msg->getModemID(), $msg->getIncID() );
			//$this->getPresenter()->setRegistratorsCalibrated();

			return true;
		}

		$this->getLogger()->log( 'Unauthorised magnet usage detected' );
		$this->getLogger()->log( 'Got command #' . $manualData['command'] );

		// TODO: Implement logging of various device status change events

		return true;
	}

	public function processReset( Message $msg )
	{
		$resetData = $msg->extractReset();

		$this->getLogger()->log( 'Got cold reset message with counter value ' . $resetData['total'] );

		return true;
	}

	public function getPreviousTotal()
	{
		$extended = $this->getPresenter()->getSingleRegistraror()->getExtended();
		return isset( $extended['total'] ) ? $extended['total'] : 0;
	}

	public function getTotalHour()
	{
		$extended = $this->getPresenter()->getSingleRegistraror()->getExtended();
		return isset( $extended['total_hour'] ) ? $extended['total_hour'] : 0;
	}

	public function getFullTotal( Message $msg, $mode='day', $incOverflowRatio=false )
	{
		$ratio = $this->getPresenter()->getOverflowRatio( $msg->getEventTime(), $mode );

		if ( $incOverflowRatio )
			$ratio++;

		$counter = $msg->extractCounterMultiplied();

		if ( $ratio == 0 )
			return $counter;

		return $msg->getOverflowedValue( $mode, $ratio, $counter );
	}

	public function convertToRealValue( $impulses, $log=false )
	{
		$unitsPerImpulse = $this->getPresenter()->getUnitsPerImpulse();

		// Reduce value by the modem initial value
		$realValue = $impulses - $this->getPresenter()->getSingleRegistraror()->getModemValue();
		if ( $realValue < 0 )
			$realValue = 0;

		if ( $log )
			$this->logger->setModification( 'water', 'reducemodemvalue', array( '-', $this->getPresenter()->getSingleRegistraror()->getModemValue(), $realValue ) );

		// Get litres
		$realValue *= $unitsPerImpulse;

		if ( $log )
			$this->logger->setModification( 'water', 'toliters', array( '*', $unitsPerImpulse, $realValue ) );

		// Convert to m3
		$realValue /= 1000;

		if ( $log )
			$this->logger->setModification( 'water', 'tom3', array( '/', 1000, $realValue ) );

		// Add meter's initial value
		$realValue += $this->getPresenter()->getSingleRegistraror()->getInitialValue();

		if ( $log )
			$this->logger->setModification( 'water', 'addinitial', array( '+', $this->getPresenter()->getSingleRegistraror()->getInitialValue(), $realValue ) );

		return $realValue;
	}

	protected function _restoreConsumptionFromRates( array $rates, $totalConsumption, $for='day' )
	{
		$pointsCount        = $for == 'day' ? 24 : 7;
		$resultConsumption  = array();
		$ratesSum           = array_sum( $rates );

		if ( $ratesSum > 0 )
		{
			$impPerRate = $totalConsumption / $ratesSum;
			for( $i = 1; $i <= $pointsCount; $i++ )
				$resultConsumption[$i-1] = $impPerRate * $rates[$i];

			$consumptionDifference = $totalConsumption - array_sum( $resultConsumption );
			if ( $consumptionDifference > .01 )
			{
				while( 1 )
				{
					foreach( $resultConsumption as $i => $cons )
					{
						$resultConsumption[$i] += .01;
						$consumptionDifference -= .01;

						if ( $consumptionDifference <= .01 )
							break 2;
					}
				}
			}
		}
		else
			$resultConsumption = array_fill( 0, $pointsCount, 0 );

		return $resultConsumption;
	}

	/**
	 * @param Message $message
	 * @param string $period
	 *
	 * @return Message|null
	 */
	protected function _getPreviousMessage( Message $message, $period='day' )
	{
		if ( $period == 'day' )
		{
			$from = $message->getEventTime() - 3600 * 25;
			$to = $message->getEventTime() - 3600;
		}
		else
		{
			$from = $message->getEventTime() - ( 3600 * 24 * 7 + 900 );
			$to = $message->getEventTime() - ( 3600 * 24 * 6 - 900 );
		}

		/** @var \Cabinet\Datahandlers\IncomingHandler $incomingHandler */
		$incomingHandler = $this->getServiceLocator()->get( 'IncomingHandler' );

		$criteria = new Where();
		$criteria->equalTo( 'modem_id', $message->getModemID() );
		$criteria->in( 'type', [ $message->getType(), Message::TYPE_MANUAL ] );
		$criteria->between( 'event_time', $from, $to );

		$packets = $incomingHandler->findPacket( $criteria, 'event_time DESC', 50 );
		if ( $packets->count() == 0 )
			return null;

		foreach( $packets as $packet )
		{
			$prevMessage = new Message( $packet->getArrayCopy(), $this, false );
			if ( $prevMessage->getType() == $message->getType() )
				return $prevMessage;

			if ( $prevMessage->getType() == Message::TYPE_MANUAL )
			{
				$messageInfo = $prevMessage->extractManual();
				if ( in_array( $messageInfo['command'], [ 5, 33 ] ) )
					return $prevMessage;
			}
		}

		return null;
	}

	public function _getPrePreviousMessage( Message $message, $period='day' )
	{
		$timeSlot = $period == 'day' ? 3600 * 25 : 3600 * 25 * 7;
		$less = $message->getEventTime() - $timeSlot;

		/** @var \Cabinet\Datahandlers\IncomingHandler $incomingHandler */
		$incomingHandler = $this->getServiceLocator()->get( 'IncomingHandler' );

		$criteria = new Where();
		$criteria->equalTo( 'modem_id', $message->getModemID() );
		$criteria->equalTo( 'type', $message->getType() );
		$criteria->lessThanOrEqualTo( 'event_time', $less );

		$packets = $incomingHandler->findPacket( $criteria, 'event_time DESC', 1 );
		if ( $packets->count() == 0 )
			return null;

		$packetData = $packets->current()->getArrayCopy();
		if ( $packetData['event_time'] < $message->getEventTime() - $timeSlot * 3 )
			return null;

		$prevMessage = new Message( $packetData, $this, false );

		return $prevMessage;
	}

	/**
	 * @param Message $message
	 *
	 * @return Message|null
	 */
	public function getLastModemMessage( Message $message, $type='daily' )
	{
		/** @var \Cabinet\Datahandlers\IncomingHandler $incomingHandler */
		$incomingHandler = $this->getServiceLocator()->get( 'IncomingHandler' );

		$criteria = new Where();
		$criteria->equalTo( 'modem_id', $message->getModemID() );
		$criteria->equalTo( 'type', $type );
		$criteria->lessThan( 'event_time', $message->getEventTime() );

		$packets = $incomingHandler->findPacket( $criteria, 'event_time DESC', 1 );
		if ( $packets->count() == 0 )
			return null;

		$lastMessage = new Message( $packets->current()->getArrayCopy(), $this, false );

		return $lastMessage;
	}

	/**
	 * @param Message $message
	 *
	 * @return Message[]|null
	 */
	protected function _getPartialProcessedMessages( Message $message )
	{
		/** @var \Cabinet\Datahandlers\IncomingHandler $incomingHandler */
		$incomingHandler = $this->getServiceLocator()->get( 'IncomingHandler' );

		$criteria = new Where();
		$criteria->equalTo( 'modem_id', $message->getModemID() );
		$criteria->equalTo( 'type', Message::TYPE_DAILY );
		$criteria->equalTo( 'processed', 'P' );
		$criteria->greaterThanOrEqualTo( 'event_time', $message->getEventTime() - 3620 * 24 * 7 );
		$criteria->lessThanOrEqualTo( 'event_time', $message->getEventTime() );

		$packets = $incomingHandler->findPacket( $criteria );
		if ( $packets->count() == 0 )
			return null;

		$messages = array();
		foreach( $packets as $packet )
		{
			$timeDiff = $message->getEventTime() - $packet->event_time;
			$index = (int) round( ( 604800 - $timeDiff ) / 86400 );   // 604800 seconds in week, 86400 seconds in day

			$messages[$index] = new Message( $packet->getArrayCopy(), $this, false );
		}

		return $messages;
	}

	protected function _isOverflow( Message $currentMessage, Message $previousMessage )
	{
		$currentCounter = $currentMessage->extractCounterMultiplied();
		$previousCounter = $previousMessage->extractCounterMultiplied();

		return $currentCounter < $previousCounter;
	}

	protected function _saveHourly( Message $msg, $total, $hourly )
	{
		$timestamp = $this->getRoundedHour( $msg->getEventTime() );
		$hourly = array_reverse( $hourly );
		$processedValues = [];
		$hourlyReal = [];
		foreach( $hourly as $i => $hourConsumption )
		{
			$prevTotal = $this->convertToRealValue( $total, false );

			$hour = $timestamp - 3600 * ( $i + 1 );
			$total -= $hourConsumption;

			$realValue = $this->convertToRealValue( $total, true );

			$hourlyReal[] = round( $prevTotal - $realValue, 3 );

			$processedValues[] = array(
				'channel' => 'water',
				'ts' => $hour,
				'realValue' => $realValue
			);
		}

		$values = array();
		if ( sizeof( $processedValues ) > 0 )
		{
			$reversedProcessedValues = array_reverse( $processedValues );
			foreach( $reversedProcessedValues as $value )
			{
				$values[] = array(
					'registrator_id' => $this->getPresenter()->getSingleRegistraror()->getId(),
					'timestamp' => $value['ts'],
					'value' => $value['realValue']
				);
			}

			$this->_massSaveProcessedValues( $values );
		}

		return true;
	}

	protected function _saveWeekly( Message $msg, $total, $weekly )
	{
		$timestamp = $this->getRoundedHour( $msg->getEventTime() );
		$weekly = array_reverse( $weekly );
		foreach( $weekly as $i => $dayConsumption )
		{
			$ts = $timestamp - 3600 * 24 * ( $i + 1 );
			$total -= $dayConsumption;

			$realValue = $this->convertToRealValue( $total, true );

			$this->_saveProcessedValue( $this->getPresenter()->getSingleRegistraror()->getId(), $ts, $realValue, false );
		}

		return true;
	}

	protected function _saveTotal( Message $msg, $total )
	{
		$timestamp = $this->getRoundedHour( $msg->getEventTime() );
		$realValue = $this->convertToRealValue( $total, true );

		$this->getLogger()->log( 'Calculated total value: ' . $realValue );

		$registrator = $this->getPresenter()->getSingleRegistraror();
		if ( $registrator === false )
		{
			$this->getLogger()->log( 'Registrator was not found' );
			return false;
		}

		if ( $registrator->getLastValueTimestamp() < $timestamp )
			$this->_updateRegistratorWithLatestValue( $registrator->getId(), $timestamp, $realValue );

		$this->_saveProcessedValue( $registrator->getId(), $timestamp, $realValue );

		return true;
	}

	public function configure( $modemId, $params=array() )
	{
		return $this->getPresenter()->registerDeviceAndRegistrator( $modemId, $params );
	}

	public function isConfigured( $modemId )
	{
		return $this->getPresenter()->hasDevice( $modemId );
	}
}