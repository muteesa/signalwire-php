<?php
require_once dirname(__FILE__) . '/BaseActionCase.php';

use SignalWire\Messages\Execute;
use SignalWire\Relay\Calling\Call;

class RelayCallingCallTest extends RelayCallingBaseActionCase
{
  protected function setUp() {
    parent::setUp();
    $this->_successResponse = \React\Promise\resolve(json_decode('{"result":{"code":"200","message":"message","control_id":"' . self::UUID . '"}}'));
    $this->_failResponse = \React\Promise\reject(json_decode('{"result":{"code":"400","message":"some error","control_id":"' . self::UUID . '"}}'));

    $this->stateNotificationCreated = json_decode('{"event_type":"calling.call.state","params":{"call_state":"created","direction":"inbound","device":{"type":"phone","params":{"from_number":"+1234","to_number":"15678"}},"tag":"'.self::UUID.'","call_id":"call-id","node_id":"node-id"}}');
    $this->stateNotificationAnswered = json_decode('{"event_type":"calling.call.state","params":{"call_state":"answered","direction":"inbound","device":{"type":"phone","params":{"from_number":"+1234","to_number":"15678"}},"call_id":"call-id","node_id":"node-id"}}');
    $this->stateNotificationEnding = json_decode('{"event_type":"calling.call.state","params":{"call_state":"ending","end_reason":"busy","direction":"inbound","device":{"type":"phone","params":{"from_number":"+1234","to_number":"15678"}},"call_id":"call-id","node_id":"node-id"}}');
    $this->stateNotificationEnded = json_decode('{"event_type":"calling.call.state","params":{"call_state":"ended","end_reason":"busy","direction":"inbound","device":{"type":"phone","params":{"from_number":"+1234","to_number":"15678"}},"call_id":"call-id","node_id":"node-id"}}');
    $this->connectNotificationFailed = json_decode('{"event_type":"calling.call.connect","params":{"connect_state":"failed","peer":{"call_id":"peer-call-id","node_id":"peer-node-id"},"call_id":"call-id","node_id":"node-id"}}');
    $this->connectNotificationDisconnected = json_decode('{"event_type":"calling.call.connect","params":{"connect_state":"disconnected","peer":{"call_id":"peer-call-id","node_id":"peer-node-id"},"call_id":"call-id","node_id":"node-id"}}');
    $this->faxNotificationPage = json_decode('{"event_type":"calling.call.fax","params":{"control_id":"'.self::UUID.'","call_id":"call-id","node_id":"node-id","fax":{"type":"page","params":{"direction":"send","pages":"1"}}}}');
    $this->faxNotificationFinished = json_decode('{"event_type":"calling.call.fax","params":{"control_id":"'.self::UUID.'","call_id":"call-id","node_id":"node-id","fax":{"type":"finished","params":{"direction":"send","identity":"+1xxx","remote_identity":"+1yyy","document":"file.pdf","success":true,"result":"1231","result_text":"","pages":"1"}}}}');
  }

  public function testDialSuccess(): void {
    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.dial',
      'params' => [
        'tag' => self::UUID,
        'devices' => $this->call->targets
      ]
    ]);

    $this->_mockSuccessResponse($msg);

    $this->call->dial()->done(function($result) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\DialResult', $result);
      $this->assertTrue($result->isSuccessful());
      $this->assertEquals($result->getCall(), $this->call);
    });

    $this->calling->notificationHandler($this->stateNotificationCreated);
    $this->calling->notificationHandler($this->stateNotificationAnswered);
  }

  public function testDialFail(): void {
    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.dial',
      'params' => [
        'tag' => self::UUID,
        'devices' => $this->call->targets
      ]
    ]);

    $this->_mockFailResponse($msg);

    $this->call->dial()->done(function($result) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\DialResult', $result);
      $this->assertFalse($result->isSuccessful());
      $this->assertEquals($result->getEvent(), null);
    });

    $this->calling->notificationHandler($this->stateNotificationCreated);
    $this->calling->notificationHandler($this->stateNotificationAnswered);
  }

  public function testHangupSuccess(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.end',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id',
        'reason' => 'hangup'
      ]
    ]);

    $this->_mockSuccessResponse($msg);

    $this->call->hangup()->done(function($result) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\HangupResult', $result);
      $this->assertEquals($result->getReason(), 'busy');
      $this->assertEquals($result->getEvent()->payload->direction, 'inbound');
    });
    $this->calling->notificationHandler($this->stateNotificationEnded);
  }

  public function testHangupFail(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.end',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id',
        'reason' => 'hangup'
      ]
    ]);

    $this->_mockFailResponse($msg);

    $this->call->hangup()->done(function($result) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\HangupResult', $result);
      $this->assertFalse($result->isSuccessful());
      $this->assertEquals($result->getEvent(), null);
    });
    $this->calling->notificationHandler($this->stateNotificationEnded);
  }

  public function testHangupIfAlreadyEnded(): void {
    $this->_setCallReady();
    $this->calling->notificationHandler($this->stateNotificationEnding);

    $this->client->connection->expects($this->never())->method('send');

    $this->call->hangup()->done(function($result) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\HangupResult', $result);
      $this->assertFalse($result->isSuccessful());
      $this->assertNull($result->getEvent());
    });
  }

  public function testAnswerSuccess(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.answer',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id'
      ]
    ]);

    $this->_mockSuccessResponse($msg);

    $this->call->answer()->done(function($result) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\AnswerResult', $result);
      $this->assertTrue($result->isSuccessful());
      $this->assertEquals($result->getEvent()->payload->direction, 'inbound');
    });
    $this->calling->notificationHandler($this->stateNotificationAnswered);
  }

  public function testAnswerFail(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.answer',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id'
      ]
    ]);

    $this->_mockFailResponse($msg);

    $this->call->answer()->done(function($result) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\AnswerResult', $result);
      $this->assertFalse($result->isSuccessful());
      $this->assertEquals($result->getEvent(), null);
    });
    $this->calling->notificationHandler($this->stateNotificationAnswered);
  }

  public function testWaitForAnswered(): void {
    $this->call->waitFor('answered')->done(function($check) {
      $this->assertTrue($check);
    });

    $this->calling->notificationHandler($this->stateNotificationCreated);
    $this->calling->notificationHandler($this->stateNotificationAnswered);
  }

  public function testWaitForAnsweredAlreadyDone(): void {
    $this->call->state = 'answered';

    $this->call->waitFor('ringing', 'answered')->done(function($check) {
      $this->assertTrue($check);
    });
  }

  public function testWaitForEnded(): void {
    $this->call->waitFor('ending', 'ended')->done(function($check) {
      $this->assertTrue($check);
    });

    $this->calling->notificationHandler($this->stateNotificationCreated);
    $this->calling->notificationHandler($this->stateNotificationEnded);
  }

  public function testWaitForUnansweredCall(): void {
    $this->call->waitFor('answered')->done(function($check) {
      $this->assertFalse($check);
    });

    $this->calling->notificationHandler($this->stateNotificationCreated);
    $this->calling->notificationHandler($this->stateNotificationEnded);
  }

  public function testFaxReceiveSuccess(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.receive_fax',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id',
        'control_id' => self::UUID
      ]
    ]);

    $this->_mockSuccessResponse($msg);

    $this->call->faxReceive()->done([$this, '__syncFaxCheck']);
    $this->calling->notificationHandler($this->faxNotificationFinished);
  }

  public function testFaxReceiveFail(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.receive_fax',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id',
        'control_id' => self::UUID
      ]
    ]);

    $this->_mockFailResponse($msg);

    $this->call->faxReceive()->done(function ($result) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\FaxResult', $result);
      $this->assertFalse($result->isSuccessful());
    });
    $this->calling->notificationHandler($this->faxNotificationFinished);
  }

  public function testFaxReceiveAsyncSuccess(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.receive_fax',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id',
        'control_id' => self::UUID,

      ]
    ]);

    $this->_mockSuccessResponse($msg);

    $this->call->faxReceiveAsync()->done([$this, '__asyncFaxCheck']);
  }

  public function testFaxReceiveAsyncFail(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.receive_fax',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id',
        'control_id' => self::UUID,

      ]
    ]);

    $this->_mockFailResponse($msg);

    $this->call->faxReceiveAsync()->done(function ($action) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Actions\FaxAction', $action);
      $this->assertTrue($action->isCompleted());
      $this->assertEquals($action->getState(), 'failed');
    });
  }

  public function testFaxSendSuccess(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.send_fax',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id',
        'control_id' => self::UUID,
        'document' => 'document.pdf'
      ]
    ]);

    $this->_mockSuccessResponse($msg);

    $this->call->faxSend('document.pdf')->done([$this, '__syncFaxCheck']);
    $this->calling->notificationHandler($this->faxNotificationFinished);
  }

  public function testFaxSendFail(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.send_fax',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id',
        'control_id' => self::UUID,
        'document' => 'document.pdf'
      ]
    ]);

    $this->_mockFailResponse($msg);

    $this->call->faxSend('document.pdf')->done(function ($result) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\FaxResult', $result);
      $this->assertFalse($result->isSuccessful());
    });
    $this->calling->notificationHandler($this->faxNotificationFinished);
  }

  public function testFaxSendAsyncSuccess(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.send_fax',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id',
        'control_id' => self::UUID,
        'document' => 'document.pdf',
        'header_info' => 'custom header'
      ]
    ]);

    $this->_mockSuccessResponse($msg);

    $this->call->faxSendAsync('document.pdf', null, 'custom header')->done([$this, '__asyncFaxCheck']);
  }

  public function testFaxSendAsyncFail(): void {
    $this->_setCallReady();

    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.send_fax',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id',
        'control_id' => self::UUID,
        'document' => 'document.pdf',
        'header_info' => 'custom header'
      ]
    ]);

    $this->_mockFailResponse($msg);

    $this->call->faxSendAsync('document.pdf', null, 'custom header')->done(function ($action) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Actions\FaxAction', $action);
      $this->assertTrue($action->isCompleted());
      $this->assertEquals($action->getState(), 'failed');
    });
  }

  public function testDisconnectSuccess(): void {
    $this->_setCallReady();
    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.disconnect',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id'
      ]
    ]);

    $this->_mockSuccessResponse($msg);

    $this->call->peer = new Call($this->calling, new \stdClass);
    $this->call->disconnect()->done(function($result) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\DisconnectResult', $result);
      $this->assertNull($this->call->peer);
      $this->assertTrue($result->isSuccessful());
    });
    $this->calling->notificationHandler($this->connectNotificationDisconnected);
  }

  public function testDisconnectFail(): void {
    $this->_setCallReady();
    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.disconnect',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id'
      ]
    ]);

    $this->_mockFailResponse($msg);

    $peer = new Call($this->calling, new \stdClass);
    $this->call->peer = $peer;
    $this->call->disconnect()->done(function($result) use (&$peer) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\DisconnectResult', $result);
      $this->assertEquals($peer, $this->call->peer);
      $this->assertFalse($result->isSuccessful());
    });
  }

  public function testDisconnectWithEventFailed(): void {
    $this->_setCallReady();
    $msg = new Execute([
      'protocol' => 'signalwire_calling_proto',
      'method' => 'calling.disconnect',
      'params' => [
        'call_id' => 'call-id',
        'node_id' => 'node-id'
      ]
    ]);

    $this->_mockSuccessResponse($msg);

    $peer = new Call($this->calling, new \stdClass);
    $this->call->peer = $peer;
    $this->call->disconnect()->done(function($result) use (&$peer) {
      $this->assertInstanceOf('SignalWire\Relay\Calling\Results\DisconnectResult', $result);
      $this->assertEquals($peer, $this->call->peer);
      $this->assertFalse($result->isSuccessful());
    });
    $this->calling->notificationHandler($this->connectNotificationFailed);
  }

  /**
   * Callable to not repeat the same function for every SYNC fax test
   */
  public function __syncFaxCheck($result) {
    $this->assertInstanceOf('SignalWire\Relay\Calling\Results\FaxResult', $result);
    $this->assertTrue($result->isSuccessful());
    $this->assertEquals($result->getDocument(), 'file.pdf');
    $this->assertEquals($result->getPages(), '1');
    $this->assertEquals($result->getIdentity(), '+1xxx');
    $this->assertObjectHasAttribute('type', $result->getEvent()->payload);
    $this->assertObjectHasAttribute('params', $result->getEvent()->payload);
  }

  /**
   * Callable to not repeat the same function for every ASYNC fax test
   */
  public function __asyncFaxCheck($action) {
    $this->assertInstanceOf('SignalWire\Relay\Calling\Actions\FaxAction', $action);
    $this->assertInstanceOf('SignalWire\Relay\Calling\Results\FaxResult', $action->getResult());
    $this->assertFalse($action->isCompleted());

    $this->calling->notificationHandler($this->faxNotificationFinished);

    $this->assertTrue($action->isCompleted());
  }
}
