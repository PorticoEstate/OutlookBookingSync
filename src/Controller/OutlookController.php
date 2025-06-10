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

		// Create HTTP client with a Guzzle config to specify proxy
		if (!empty($_ENV['httpproxy_server']))
		{
			$guzzleConfig = [
				"proxy" => "{$_ENV['httpproxy_server']}:{$_ENV['httpproxy_port']}"
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
	 * Get available groups using direct API call to Microsoft Graph
	 */
	public function getAvailableGroups(Request $request, Response $response, $args)
	{
		try
		{
			// Get the request adapter from the Graph service client
			$requestAdapter = $this->graphServiceClient->getRequestAdapter();

			// Make a direct API call to get groups
			$groupsRequest = new \Microsoft\Kiota\Abstractions\RequestInformation();
			$groupsRequest->urlTemplate = "https://graph.microsoft.com/v1.0/groups?\$top=999";
			$groupsRequest->httpMethod = \Microsoft\Kiota\Abstractions\HttpMethod::GET;
			$groupsRequest->addHeader("Accept", "application/json");

			$allGroups = [];
			$nextLink = null;

			do {
				// Update URL for pagination if we have a next link
				if ($nextLink) {
					$groupsRequest->urlTemplate = $nextLink;
				}

				$groupsResponse = $requestAdapter->sendAsync(
					$groupsRequest,
					[\Microsoft\Graph\Generated\Models\GroupCollectionResponse::class, 'createFromDiscriminatorValue'],
					[ODataError::class, 'createFromDiscriminatorValue']
				)->wait();

				if (method_exists($groupsResponse, 'getValue') && !empty($groupsResponse->getValue()))
				{
					$groups = $groupsResponse->getValue();

					foreach ($groups as $group)
					{
						$groupData = [
							'id' => $group->getId(),
							'displayName' => $group->getDisplayName() ?? 'N/A',
							'description' => $group->getDescription() ?? 'N/A',
							'mail' => $group->getMail() ?? 'N/A',
							'groupTypes' => $group->getGroupTypes() ?? []
						];

						$allGroups[] = $groupData;
					}
				}

				// Check for next page
				$nextLink = $groupsResponse ? $groupsResponse->getOdataNextLink() : null;

			} while ($nextLink);

			$response->getBody()->write(json_encode([
				'totalGroups' => count($allGroups),
				'groups' => $allGroups
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

	/**
	 * Get group members using direct API call to Microsoft Graph
	 */
	public function getAvailableRooms(Request $request, Response $response, $args)
	{
		// Query the Microsoft Graph API like this:
		// https://graph.microsoft.com/v1.0/groups/{GROUP_ID}/members

		try
		{
			// Get group ID from environment variable or use default
			$groupId = $_ENV['GRAPH_GROUP_ID'] ?? '90ba4505-3855-4739-81fa-6b0008ae9216';

			// Get the request adapter from the Graph service client
			$requestAdapter = $this->graphServiceClient->getRequestAdapter();

			// Make a direct API call to get group members
			$groupMembersRequest = new \Microsoft\Kiota\Abstractions\RequestInformation();
			$groupMembersRequest->urlTemplate = "https://graph.microsoft.com/v1.0/groups/{$groupId}/members";
			$groupMembersRequest->httpMethod = \Microsoft\Kiota\Abstractions\HttpMethod::GET;
			$groupMembersRequest->addHeader("Accept", "application/json");

			$groupMembersResponse = $requestAdapter->sendAsync(
				$groupMembersRequest,
				[\Microsoft\Graph\Generated\Models\DirectoryObjectCollectionResponse::class, 'createFromDiscriminatorValue'],
				[ODataError::class, 'createFromDiscriminatorValue']
			)->wait();

			$allMembers = [];

			if ($groupMembersResponse)
			{
				$members = $groupMembersResponse->getValue();
				if ($members && !empty($members))
				{
					foreach ($members as $member)
					{
						$memberData = [
							'id' => $member->getId(),
							'displayName' => $member->getDisplayName() ?? 'N/A',
							'@odata.type' => $member->getOdataType()
						];

						// Add additional properties if it's a User object
						if ($member instanceof \Microsoft\Graph\Generated\Models\User)
						{
							$memberData['userPrincipalName'] = $member->getUserPrincipalName();
							$memberData['mail'] = $member->getMail();
							$memberData['jobTitle'] = $member->getJobTitle();
						}

						$allMembers[] = $memberData;
					}
				}
			}

			$response->getBody()->write(json_encode([
				'totalMembers' => count($allMembers),
				'groupId' => $groupId,
				'members' => $allMembers
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
