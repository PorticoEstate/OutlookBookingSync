<?php

namespace App\Controller;

use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Core\Authentication\GraphPhpLeagueAuthenticationProvider;
use Microsoft\Graph\GraphRequestAdapter;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Graph\Core\GraphClientFactory;
use Microsoft\Graph\Generated\Users\Item\MailFolders\Item\Messages\MessagesRequestBuilderGetRequestConfiguration;
use Microsoft\Graph\Generated\Models\Message;
use Microsoft\Graph\Generated\Users\Item\Messages\Item\Move\MovePostRequestBody;
use Microsoft\Kiota\Abstractions\ApiException;
use GuzzleHttp\Client;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Microsoft\Graph\Generated\Models\Room;
use Microsoft\Graph\Generated\Models\ODataErrors\ODataError;

class OutlookController
{
	/**
	 * @var GraphServiceClient
	 */
	private $graphServiceClient;
	/**
	 * The user principal name of the user whose mailbox you want to access
	 * @var string
	 */
	private $userPrincipalName;
	/**
	 * Constructor
	 */
	function __construct()
	{
		$this->initializeGraph();
	}
	/**
	 * Initialize the Microsoft Graph client
	 */
	function initializeGraph()
	{
		//https://github.com/microsoftgraph/msgraph-sdk-php/blob/main/docs/Examples.md
		//https://learn.microsoft.com/en-us/graph/tutorials/php?tabs=aad&tutorial-step=3
		//https://github.com/microsoftgraph/msgraph-sdk-php/issues/1483
		$tenantId = $_ENV['GRAPH_TENANT_ID'];
		$clientId = $_ENV['GRAPH_CLIENT_ID'];
		$clientSecret = $_ENV['GRAPH_CLIENT_SECRET'];
		$this->userPrincipalName = $_ENV['GRAPH_USER_PRINCIPAL_NAME'];


		$tokenRequestContext = new ClientCredentialContext(
			$tenantId,
			$clientId,
			$clientSecret
		);

		$authProvider = new GraphPhpLeagueAuthenticationProvider($tokenRequestContext);
		$serverSettings = [];
		// Create HTTP client with a Guzzle config to specify proxy
		if (!empty($serverSettings['httpproxy_server']))
		{
			$guzzleConfig = [
				"proxy" => "{$serverSettings['httpproxy_server']}:{$serverSettings['httpproxy_port']}"
			];
		}
		else
		{
			$guzzleConfig = array();
		}

		$httpClient = GraphClientFactory::createWithConfig($guzzleConfig);
		$requestAdapter = new GraphRequestAdapter($authProvider, $httpClient);
		$this->graphServiceClient = GraphServiceClient::createWithRequestAdapter($requestAdapter);
	}

	/**
	 * Get available meeting rooms using direct API call to Microsoft Graph
	 */
	public function getAvailableRooms(Request $request, Response $response, $args)
	{
		try
		{
			// Get the request adapter from the Graph service client
			$requestAdapter = $this->graphServiceClient->getRequestAdapter();

			// Make a direct API call to get room lists
			$roomListsRequest = new \Microsoft\Kiota\Abstractions\RequestInformation();
			$roomListsRequest->urlTemplate = "https://graph.microsoft.com/v1.0/places/microsoft.graph.roomList";
			$roomListsRequest->httpMethod = \Microsoft\Kiota\Abstractions\HttpMethod::GET;
			$roomListsRequest->addHeader("Accept", "application/json");

			$roomListsResponse = $requestAdapter->sendAsync(
				$roomListsRequest,
				[Room::class, 'createFromDiscriminatorValue'],
				[ODataError::class, 'createFromDiscriminatorValue']
			)->wait();

			$result = [];
			$allRooms = [];

			if ($roomListsResponse instanceof \Microsoft\Graph\Generated\Models\RoomCollectionResponse && !empty($roomListsResponse->getValue()))
			{
				$roomLists = $roomListsResponse->getValue();

				// For each room list, get rooms directly from API
				foreach ($roomLists as $roomList)
				{
					$roomListEmail = $roomList->getEmailAddress();
					$roomListName = $roomList->getDisplayName();

					// Make direct API call to get rooms in this list
					$roomsRequest = new \Microsoft\Kiota\Abstractions\RequestInformation();
					$roomsRequest->urlTemplate = "https://graph.microsoft.com/v1.0/places/microsoft.graph.roomList/{roomListEmail}/rooms";
					$roomsRequest->urlTemplate = str_replace("{roomListEmail}", urlencode($roomListEmail), $roomsRequest->urlTemplate);
					$roomsRequest->httpMethod = \Microsoft\Kiota\Abstractions\HttpMethod::GET;
					$roomsRequest->setHeaders(["Accept" => "application/json"]);

					$roomsResponse = $requestAdapter->sendAsync(
						$roomsRequest,
						[Room::class, 'createFromDiscriminatorValue'],
						[ODataError::class, 'createFromDiscriminatorValue']
					)->wait();

					$roomsData = [];

					if ($roomsResponse instanceof \Microsoft\Graph\Generated\Models\RoomCollectionResponse && !empty($roomsResponse->getValue()))
					{
						$roomsInList = $roomsResponse->getValue();

						foreach ($roomsInList as $room)
						{
							// Only include bookable rooms (not reserved)
							if ($room->getBookingType() !== 'reserved')
							{
								$roomData = [
									'id' => $room->getId(),
									'displayName' => $room->getDisplayName(),
									'emailAddress' => $room->getEmailAddress(),
									'building' => $room->getBuilding(),
									'floorNumber' => $room->getFloorNumber(),
									'capacity' => $room->getCapacity(),
									'bookingType' => $room->getBookingType(),
									'audioDeviceName' => $room->getAudioDeviceName(),
									'videoDeviceName' => $room->getVideoDeviceName(),
									'displayDeviceName' => $room->getDisplayDeviceName(),
									'isWheelChairAccessible' => $room->getIsWheelChairAccessible()
								];

								$roomsData[] = $roomData;
								$allRooms[] = $roomData;
							}
						}
					}

					$result[] = [
						'listName' => $roomListName,
						'emailAddress' => $roomListEmail,
						'roomCount' => count($roomsData),
						'rooms' => $roomsData
					];
				}
			}

			$response->getBody()->write(json_encode([
				'totalRooms' => count($allRooms),
				'roomLists' => $result,
				'allRooms' => $allRooms
			]));
			return $response->withHeader('Content-Type', 'application/json');
		}
		catch (\Throwable $e)
		{
			$response->getBody()->write(json_encode([
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]));
			return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
		}
	}
}
