> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# Get AI Video Details

> Retrieve comprehensive information about an AI-generated video task.

### Usage Guide

* Check the status of a video generation or extension task
* Access video URLs when generation is complete
* Troubleshoot failed generation attempts

### Status Descriptions

* `wait`: Task has been submitted but not yet queued
* `queueing`: Task is waiting in processing queue
* `generating`: Video generation is in progress
* `success`: Video has been successfully generated
* `fail`: Video generation has failed

### Developer Notes

* Works with both standard video generation and video extension tasks
* For extension tasks, the `parentTaskId` field identifies the original video
* Video links are valid for 14 days, after which `expireFlag` will be set to 1


## OpenAPI

````yaml runway-api/runway-api.json get /api/v1/runway/record-detail
openapi: 3.0.0
info:
  title: Runway API
  description: kie.ai Runway API Documentation
  version: 1.0.0
  contact:
    name: Technical Support
    email: support@kie.ai
servers:
  - url: https://api.kie.ai
    description: API Server
security:
  - BearerAuth: []
paths:
  /api/v1/runway/record-detail:
    get:
      summary: Get AI Video Details
      description: >-
        Retrieve comprehensive information about an AI-generated video task.


        ### Usage Guide

        - Check the status of a video generation or extension task

        - Access video URLs when generation is complete

        - Troubleshoot failed generation attempts


        ### Status Descriptions

        - `wait`: Task has been submitted but not yet queued

        - `queueing`: Task is waiting in processing queue

        - `generating`: Video generation is in progress

        - `success`: Video has been successfully generated

        - `fail`: Video generation has failed


        ### Developer Notes

        - Works with both standard video generation and video extension tasks

        - For extension tasks, the `parentTaskId` field identifies the original
        video

        - Video links are valid for 14 days, after which `expireFlag` will be
        set to 1
      operationId: get-ai-video-details
      parameters:
        - name: taskId
          in: query
          description: >-
            Unique identifier of the video generation or extension task. This is
            the taskId returned when creating or extending an AI video.
          required: true
          schema:
            type: string
          example: ee603959-debb-48d1-98c4-a6d1c717eba6
      responses:
        '200':
          description: Request successful
          content:
            application/json:
              schema:
                allOf:
                  - type: object
                    properties:
                      code:
                        type: integer
                        enum:
                          - 200
                          - 401
                          - 404
                          - 422
                          - 429
                          - 451
                          - 455
                          - 500
                        description: >-
                          Response status code


                          - **200**: Success - Request has been processed
                          successfully

                          - **401**: Unauthorized - Authentication credentials
                          are missing or invalid

                          - **404**: Not Found - The requested resource or
                          endpoint does not exist

                          - **422**: Validation Error - The request parameters
                          failed validation checks

                          - **429**: Rate Limited - Request limit has been
                          exceeded for this resource

                          - **451**: Unauthorized - Failed to fetch the image.
                          Kindly verify any access limits set by you or your
                          service provider.

                          - **455**: Service Unavailable - System is currently
                          undergoing maintenance

                          - **500**: Server Error - An unexpected error occurred
                          while processing the request
                      msg:
                        type: string
                        description: Status message
                        example: success
                      data:
                        type: object
                        properties:
                          taskId:
                            type: string
                            description: Unique identifier of the AI video generation task
                            example: ee603959-debb-48d1-98c4-a6d1c717eba6
                          parentTaskId:
                            type: string
                            description: >-
                              For extension tasks only - the task ID of the
                              original video being extended. Empty for standard
                              generation tasks.
                            example: ''
                          generateParam:
                            type: object
                            description: Parameters used for video generation or extension
                            properties:
                              prompt:
                                type: string
                                description: >-
                                  Text prompt used to guide the AI video
                                  generation
                                example: >-
                                  A fluffy orange cat dancing energetically in a
                                  colorful room with disco lights
                              imageUrl:
                                type: string
                                description: >-
                                  Reference image URL used for video generation
                                  or the frame from which extension began
                                example: https://example.com/image.jpg
                              expandPrompt:
                                type: boolean
                                description: >-
                                  Whether AI-powered prompt enhancement was used
                                  during generation
                                example: true
                          state:
                            type: string
                            description: Current status of the video generation process
                            enum:
                              - wait
                              - queueing
                              - generating
                              - success
                              - fail
                            example: success
                          generateTime:
                            type: string
                            description: Timestamp when the video generation was completed
                            example: '2023-08-15 14:30:45'
                          videoInfo:
                            type: object
                            description: >-
                              Details about the generated video, available when
                              state is 'success'
                            properties:
                              videoId:
                                type: string
                                description: Unique identifier for the generated video file
                                example: 485da89c-7fca-4340-8c04-101025b2ae71
                              taskId:
                                type: string
                                description: >-
                                  The task ID associated with this video
                                  generation
                                example: ee603959-debb-48d1-98c4-a6d1c717eba6
                              videoUrl:
                                type: string
                                description: >-
                                  URL to access and download the generated
                                  video, valid for 14 days
                                example: https://file.com/k/xxxxxxx.mp4
                              imageUrl:
                                type: string
                                description: >-
                                  URL of a thumbnail image from the generated
                                  video
                                example: https://file.com/m/xxxxxxxx.png
                          failCode:
                            type: integer
                            format: int32
                            description: >-
                              Error code


                              - **400**: Detected inappropriate content, please
                              try replacing the video.

                              HD quality free trials complete. Subscribe now to
                              continue creating in high resolution.

                              Inappropriate content detected. Please replace the
                              image or video.

                              Please try again later. You can upgrade to
                              Standard membership to start generating now.

                              Reached the limit for concurrent generations.

                              Unsupported width or height. Please adjust the
                              size and try again.

                              Upload failed due to network reasons, please
                              re-enter

                              Your prompt was caught by our AI moderator.

                              Your prompt has triggered our AI moderator, please
                              re-enter your prompt

                              Your prompt/negative prompt cannot exceed 2048
                              characters. Please check if your input is too
                              long.

                              Your video creation prompt contains NSFW content,
                              which isn't allowed under our policy. Kindly
                              revise your prompt and generate again.

                              Incorrect image format
                            enum:
                              - 400
                          failMsg:
                            type: string
                            description: >-
                              Detailed error message explaining the reason for
                              failure
                            example: Generation failed, please try again later
                          expireFlag:
                            type: integer
                            format: int32
                            description: >-
                              Indicates if the video has expired: 0 = active
                              (still available), 1 = expired (no longer
                              available)
                            enum:
                              - 0
                              - 1
                            example: 0
        '500':
          $ref: '#/components/responses/Error'
components:
  responses:
    Error:
      description: Server Error
  securitySchemes:
    BearerAuth:
      type: http
      scheme: bearer
      bearerFormat: API Key
      description: >-
        All APIs require authentication via Bearer Token.


        Get API Key:

        1. Visit [API Key Management Page](https://kie.ai/api-key) to get your
        API Key


        Usage:

        Add to request header:

        Authorization: Bearer YOUR_API_KEY


        Note:

        - Keep your API Key secure and do not share it with others

        - If you suspect your API Key has been compromised, reset it immediately
        in the management page

````