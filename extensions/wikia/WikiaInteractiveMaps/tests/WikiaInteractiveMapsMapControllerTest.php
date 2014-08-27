<?php
class WikiaInteractiveMapsMapControllerTest extends WikiaBaseTest {

	public function setUp() {
		global $IP;
		$this->setupFile = "$IP/extensions/wikia/WikiaInteractiveMaps/WikiaInteractiveMaps.setup.php";
		parent::setUp();
	}

	public function testCreateMap_throws_bad_request_api_exception() {
		$controllerMock = $this->getWikiaInteractiveMapsMapController();
		$controllerMock->expects( $this->any() )
			->method( 'getData' )
			->will( $this->onConsecutiveCalls(
				0,
				null,
				null
			) );

		$userMock = $this->getUserMock();
		$userMock->expects( $this->never() )
			->method( 'isLoggedIn' );

		$controllerMock->wg->User = $userMock;

		$this->setExpectedException( 'BadRequestApiException' );
		$controllerMock->createMap();
	}

	public function testCreateMap_throws_invalid_parameter_api_exception() {
		$controllerMock = $this->getWikiaInteractiveMapsMapController();
		$controllerMock->expects( $this->any() )
			->method( 'getData' );

		$userMock = $this->getUserMock();
		$userMock->expects( $this->never() )
			->method( 'isLoggedIn' );

		$controllerMock->wg->User = $userMock;

		$this->setExpectedException( 'InvalidParameterApiException' );
		$controllerMock->createMap();
	}

	public function testCreateMap_throws_permission_exception_when_anon() {
		$controllerMock = $this->getWikiaInteractiveMapsMapController();

		$userMock = $this->getUserMock();
		$userMock->expects( $this->once() )
			->method( 'isLoggedIn' )
			->willReturn( false );
		$userMock->expects( $this->never() )
			->method( 'getName' );
		$userMock->expects( $this->never() )
			->method( 'isBlocked' );

		$this->mockGetDataForUserTests( $controllerMock );
		$controllerMock->wg->User = $userMock;

		$this->setExpectedException( 'WikiaInteractiveMapsPermissionException' );
		$controllerMock->createMap();
	}

	public function testCreateMap_throws_permission_exception_when_blocked() {
		$controllerMock = $this->getWikiaInteractiveMapsMapController();

		$userMock = $this->getUserMock();
		$userMock->expects( $this->once() )
			->method( 'isLoggedIn' )
			->willReturn( true );
		$userMock->expects( $this->never() )
			->method( 'getName' );
		$userMock->expects( $this->once() )
			->method( 'isBlocked' )
			->willReturn( true );

		$this->mockGetDataForUserTests( $controllerMock );
		$controllerMock->wg->User = $userMock;

		$this->setExpectedException( 'WikiaInteractiveMapsPermissionException' );
		$controllerMock->createMap();
	}

	public function testUpdateMapDeletionStatus_anon() {
		$userMock = $this->getUserMock();
		$userMock->expects( $this->once() )
			->method( 'isLoggedIn' )
			->willReturn( false );

		$requestMock = $this->getWikiaRequestMock();
		$requestMock->expects( $this->once() )
			->method( 'getVal' )
			->willReturn( 1 );
		$requestMock->expects( $this->once() )
			->method( 'getInt' )
			->willReturn( WikiaMaps::MAP_DELETED );

		$controllerMock = $this->getWikiaInteractiveMapsMapController();
		$controllerMock->expects( $this->never() )
			->method( 'getModel' );
		$controllerMock->request = $requestMock;
		$controllerMock->wg->User = $userMock;

		$controllerMock->updateMapDeletionStatus();
	}

	public function testUpdateMapDeletionStatus_blocked() {
		$userMock = $this->getUserMock();
		$userMock->expects( $this->once() )
			->method( 'isLoggedIn' )
			->willReturn( true );
		$userMock->expects( $this->once() )
			->method( 'isBlocked' )
			->willReturn( true );

		$requestMock = $this->getWikiaRequestMock();
		$requestMock->expects( $this->once() )
			->method( 'getVal' )
			->willReturn( 1 );
		$requestMock->expects( $this->once() )
			->method( 'getInt' )
			->willReturn( WikiaMaps::MAP_DELETED );

		$modelMock = $this->getMockBuilder( 'WikiaMaps' )
			->setMethods( [ 'updateMapDeletionStatus' ] )
			->disableOriginalConstructor()
			->getMock();
		$modelMock->expects( $this->once() )
			->method( 'updateMapDeletionStatus' );

		$controllerMock = $this->getWikiaInteractiveMapsMapController();
		$controllerMock->expects( $this->once() )
			->method( 'getModel' )
			->willReturn( $modelMock );
		$controllerMock->request = $requestMock;
		$controllerMock->wg->User = $userMock;

		$controllerMock->updateMapDeletionStatus();
	}

	private function getWikiaRequestMock() {
		$requestMock = $this->getMockBuilder( 'WikiaRequest' )
			->setMethods( [ 'getVal', 'getInt' ] )
			->disableOriginalConstructor()
			->getMock();

		return $requestMock;
	}

	private function getUserMock() {
		$userMock = $this->getMockBuilder( 'User' )
			->setMethods( [ 'isLoggedIn', 'getName', 'isBlocked' ] )
			->disableOriginalConstructor()
			->getMock();

		return $userMock;
	}

	private function getWikiaInteractiveMapsMapController() {
		$controllerMock = $this->getMockBuilder( 'WikiaInteractiveMapsMapController' )
			->setMethods( [ 'getData', 'getModel' ] )
			->disableOriginalConstructor()
			->getMock();

		$controllerMock->request = $this->getWikiaRequestMock();
		$controllerMock->wg->CityId = 123;

		return $controllerMock;
	}

	private function mockGetDataForUserTests( $controllerMock ) {
		$controllerMock->expects( $this->any() )
			->method( 'getData' )
			->will( $this->onConsecutiveCalls(
				1,
				'http://mocked.image.url.com',
				'Mocked Map Title'
			) );
	}

}
