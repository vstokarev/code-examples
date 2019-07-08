<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChannelRequest;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\Genre;
use App\Services\LogoManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ChannelController extends Controller
{
    /**
     * @OAS\Get(
     *   path="/channels",
     *   description="Get paginated list of channels",
     *   security={{"BearerAuth":{}}},
     *   @OAS\Parameter(
     *     description="Page number",
     *     in="query",
     *     name="page",
     *     required=false,
     *     @OAS\Schema(
     *       type="integer"
     *     )
     *   ),
     *   @OAS\Parameter(
     *     description="Channels filter based on logo field",
     *     in="query",
     *     name="filter",
     *     required=false,
     *     @OAS\Schema(
     *       type="string",
     *       enum={"withlogo", "withoutlogo", "all"}
     *     )
     *   ),
     *   @OAS\Response(
     *     response=200,
     *     description="List of registered channels",
     *     @OAS\MediaType(
     *      mediaType="application/json",
     *      @OAS\Schema(
     *          @OAS\Property(
     *              property="data",
     *              ref="#/components/schemas/Channels"
     *          ),
     *          @OAS\Property(
     *              property="meta",
     *              ref="#/components/schemas/MetaData"
     *          ),
     *          @OAS\Property(
     *              property="links",
     *              ref="#/components/schemas/LinksData"
     *          )
     *      )
     *     )
     *   ),
     *   @OAS\Response(
     *     response="default",
     *     description="Error message",
     *     @OAS\MediaType(
     *      mediaType="application/json",
     *      schema=@OAS\Property(ref="#/components/schemas/DefaultError")
     *     )
     *   )
     * )
     *
     */
    public function index(Request $request)
    {
        $filter = $request->query->get('filter');

        if ($filter == 'withlogo') {
            $collection = Channel::with(['genre'])->whereNotNull('logo')->orderBy('name', 'asc');
        } elseif ($filter == 'withoutlogo') {
            $collection = Channel::with(['genre'])->whereNull('logo')->orderBy('name', 'asc');
        } else {
            $collection = Channel::with(['genre'])->orderBy('name', 'asc');
        }

        return \App\Http\Resources\ChannelResource::collection(
            $collection->paginate(50)
        );
    }

    /**
     * @OAS\Post(
     *     path="/channels",
     *     description="Register a new channel",
     *     security={{"BearerAuth":{}}},
     *     @OAS\Response(
     *      response=201,
     *      description="A new channel has been added successfully",
     *      @OAS\MediaType(
     *          mediaType="application/json",
     *          @OAS\Schema(
     *              @OAS\Property(
     *                  property="data",
     *                  ref="#/components/schemas/Channel"
     *              )
     *          )
     *      )
     *     ),
     *     @OAS\Response(
     *      response=403,
     *      description="User does not have access to perform this operation",
     *      @OAS\MediaType(
     *          mediaType="application/json",
     *          schema=@OAS\Property(ref="#/components/schemas/ErrorResponse")
     *      )
     *     ),
     *     @OAS\Response(
     *      response=422,
     *      description="Invalid data",
     *      @OAS\MediaType(
     *          mediaType="application/json",
     *          schema=@OAS\Property(ref="#/components/schemas/ValidationErrorResponse")
     *      )
     *     ),
     *     @OAS\Response(
     *      response=500,
     *      description="Internal server error while processing the request, probably due to problems with logo upload",
     *      @OAS\MediaType(
     *          mediaType="application/json",
     *          schema=@OAS\Property(ref="#/components/schemas/ErrorResponse")
     *      )
     *     ),
     *     @OAS\Response(
     *      response="default",
     *      description="Error message",
     *      @OAS\MediaType(
     *          mediaType="application/json",
     *          schema=@OAS\Property(ref="#/components/schemas/DefaultError")
     *      )
     *     )
     * )
     *
     * @param ChannelRequest $channelRequest Request with incoming data
     * @param LogoManager $logoManager
     * @return ChannelResource
     */
    public function create(ChannelRequest $channelRequest, LogoManager $logoManager)
    {
        if (Auth::user()->is_admin == 'N') {
            return $this->respondForbidden('Only administrators have access to perform this action');
        }

        $toInsert = $channelRequest->all();

        try {
            $toInsert['logo'] = $logoManager->saveLogoFile($channelRequest->file('logo'), $toInsert['name']);
        } catch (\Exception $e) {
            return $this->respondInternalError($e->getMessage());
        }

        $channel = Channel::create($toInsert);

        return (new ChannelResource($channel))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * @OAS\Patch(
     *     path="/channels/{channel}",
     *     description="Update channel data",
     *     security={{"BearerAuth":{}}},
     *     @OAS\Parameter(
     *      description="Channel id",
     *      in="path",
     *      name="channel",
     *      required=true,
     *      @OAS\Schema(
     *          type="integer"
     *      )
     *     ),
     *     @OAS\Response(
     *      response=200,
     *      description="Channel data has been updated successfully",
     *      @OAS\MediaType(
     *          mediaType="application/json",
     *          @OAS\Schema(
     *              @OAS\Property(
     *                  property="data",
     *                  ref="#/components/schemas/Channel"
     *              )
     *          )
     *      )
     *     ),
     *     @OAS\Response(
     *      response=403,
     *      description="User does not have access to perform this operation",
     *      @OAS\MediaType(
     *          mediaType="application/json",
     *          schema=@OAS\Property(ref="#/components/schemas/ErrorResponse")
     *      )
     *     ),
     *     @OAS\Response(
     *      response=422,
     *      description="Invalid data",
     *      @OAS\MediaType(
     *          mediaType="application/json",
     *          schema=@OAS\Property(ref="#/components/schemas/ValidationErrorResponse")
     *      )
     *     ),
     *     @OAS\Response(
     *      response=500,
     *      description="Internal server error while processing the request, probably due to problems with logo upload",
     *      @OAS\MediaType(
     *          mediaType="application/json",
     *          schema=@OAS\Property(ref="#/components/schemas/ErrorResponse")
     *      )
     *     ),
     *     @OAS\Response(
     *      response="default",
     *      description="Error message",
     *      @OAS\MediaType(
     *          mediaType="application/json",
     *          schema=@OAS\Property(ref="#/components/schemas/DefaultError")
     *      )
     *     )
     * )
     *
     * @param ChannelRequest $channelRequest Request with incoming data
     * @param Channel $channel Channel to update
     * @param LogoManager $logoManager
     * @return \Illuminate\Http\Response
     */
    public function update(ChannelRequest $channelRequest, Channel $channel, LogoManager $logoManager)
    {
        if (Auth::user()->is_admin == 'N') {
            return $this->respondForbidden('Only administrators have access to perform this action');
        }

        $toUpdate = $channelRequest->all();
        $oldLogoName = $channel->logo;

        try {
            $toUpdate['logo'] = $logoManager->saveLogoFile($channelRequest->file('logo'), $toUpdate['name']) ?? $channel->logo;
        } catch (\Exception $e) {
            return $this->respondInternalError($e->getMessage());
        }

        if ($oldLogoName != '') {
            if ($oldLogoName != '' && ($oldLogoName != $toUpdate['logo'])) {
                $logoManager->removeLogoFile($toUpdate['logo']);
            } elseif ($channel->name != $toUpdate['name']) {
                $toUpdate['logo'] = $logoManager->renameLogoFile($oldLogoName, $toUpdate['name']);
            }
        }

        $channel->update($toUpdate);

        return (new ChannelResource($channel));
    }

    /**
     * @OAS\Delete(
     *   path="/channels/{channel}",
     *   description="Delete a channel",
     *   security={{"BearerAuth":{}}},
     *   @OAS\Parameter(
     *     description="Channel id",
     *     in="path",
     *     name="channel",
     *     required=true,
     *     @OAS\Schema(
     *       type="integer"
     *     )
     *   ),
     *   @OAS\Response(
     *     response=204,
     *     description="Channel has been deleted successfully"
     *   ),
     *   @OAS\Response(
     *    response=403,
     *    description="User does not have access to perform this operation",
     *    @OAS\MediaType(
     *        mediaType="application/json",
     *        schema=@OAS\Property(ref="#/components/schemas/ErrorResponse")
     *    )
     *   ),
     *   @OAS\Response(
     *      response="default",
     *      description="Error message",
     *      @OAS\MediaType(
     *          mediaType="application/json",
     *          schema=@OAS\Property(ref="#/components/schemas/DefaultError")
     *      )
     *   )
     * )
     *
     * @param Channel $channel Channel to be deleted
     * @return \Illuminate\Http\Response
     * @throws \Exception
     */
    public function delete(Channel $channel, LogoManager $logoManager)
    {
        if (Auth::user()->is_admin == 'N') {
            return $this->respondForbidden('Only administrators have access to perform this action');
        }

        $logoManager->removeLogoFile($channel->logo);
        $channel->delete();

        return response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @OAS\Get(
     *   path="/channels/genre/{genre}",
     *   description="Get channels by genre",
     *   security={{"BearerAuth":{}}},
     *   @OAS\Parameter(
     *     description="Genre id",
     *     in="path",
     *     name="genre",
     *     required=true,
     *     @OAS\Schema(
     *       type="integer"
     *     )
     *   ),
     *   @OAS\Response(
     *     response=200,
     *     description="List of channels of specified genre",
     *     @OAS\MediaType(
     *      mediaType="application/json",
     *      @OAS\Schema(
     *          @OAS\Property(
     *              property="data",
     *              ref="#/components/schemas/Channels"
     *          )
     *      )
     *     )
     *   ),
     *   @OAS\Response(
     *     response="default",
     *     description="Error message",
     *     @OAS\MediaType(
     *      mediaType="application/json",
     *      schema=@OAS\Property(ref="#/components/schemas/DefaultError")
     *     )
     *   )
     * )
     *
     * @param Genre $genre
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection|Response
     */
    public function getChannelsByGenre(Genre $genre)
    {
        return ChannelResource::collection(Channel::where('genre_id', $genre->id)->get());
    }
}