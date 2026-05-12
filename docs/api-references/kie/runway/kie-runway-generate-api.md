> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# Generate AI Video

> Create dynamic AI-generated videos from text prompts or image references.

### Usage Guide

* Create short videos (5-10 seconds) using AI visualization
* Generate videos based on text descriptions or reference images
* Suitable for social media content, digital art, or concept visualization

### Parameter Details

* `prompt` describes what you want to show in the video
* `imageUrl` provides visual reference for AI
* `aspectRatio` determines video orientation (vertical or horizontal)
* `duration` control the video duration (5 or 10 seconds), where if a 10-second video is selected, 1080p resolution cannot be selected
* `quality` video resolution (720p or 1080p), where if 1080p is selected, 10-second video cannot be generated
* `waterMark` video watermark text content, empty string means no watermark

### Developer Notes

* Generated videos are stored for 14 days before automatic deletion
* For text-only generation, aspect ratio must be explicitly specified


## OpenAPI

````yaml runway-api/runway-api.json post /api/v1/runway/generate
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
  /api/v1/runway/generate:
    post:
      summary: Generate AI Video
      description: >-
        Create dynamic AI-generated videos from text prompts or image
        references.


        ### Usage Guide

        - Create short videos (5-10 seconds) using AI visualization

        - Generate videos based on text descriptions or reference images

        - Suitable for social media content, digital art, or concept
        visualization


        ### Parameter Details

        - `prompt` describes what you want to show in the video

        - `imageUrl` provides visual reference for AI

        - `aspectRatio` determines video orientation (vertical or horizontal)

        - `duration` control the video duration (5 or 10 seconds), where if a
        10-second video is selected, 1080p resolution cannot be selected 

        - `quality` video resolution (720p or 1080p), where if 1080p is
        selected, 10-second video cannot be generated

        - `waterMark` video watermark text content, empty string means no
        watermark


        ### Developer Notes

        - Generated videos are stored for 14 days before automatic deletion

        -  For text-only generation, aspect ratio must be explicitly specified
      operationId: generate-ai-video
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required:
                - prompt
                - duration
                - quality
              properties:
                prompt:
                  type: string
                  description: >-
                    Descriptive text that guides the AI video generation. Be
                    specific about subject, action, style, and setting. When
                    used with an image, describes how to animate or modify the
                    image content. Maximum length is 1800 characters.
                  example: >-
                    A fluffy orange cat dancing energetically in a colorful room
                    with disco lights
                imageUrl:
                  type: string
                  description: >-
                    Optional reference image URL to base the video on. When
                    provided, the AI will create a video animating or extending
                    this image.
                  example: https://example.com/cat-image.jpg
                duration:
                  type: number
                  description: >-
                    Video duration, optional values are 5 or 10. If 10-second
                    video is selected, 1080p resolution cannot be used
                  example: '5'
                quality:
                  type: string
                  description: >-
                    Video resolution, optional values are 720p or 1080p. If
                    1080p is selected, 10-second video cannot be generated
                  example: 720p
                aspectRatio:
                  type: string
                  enum:
                    - '16:9'
                    - '4:3'
                    - '1:1'
                    - '3:4'
                    - '9:16'
                  description: >-
                    Video aspect ratio parameter. **Required parameter for
                    text-to-video generation requests. This parameter is invalid
                    when imageUrl is passed, and the aspect ratio will
                    ultimately be determined by the provided image.**
                  example: '9:16'
                waterMark:
                  type: string
                  description: >-
                    Video watermark text content. An empty string indicates no
                    watermark, while a non-empty string will display the
                    specified text as a watermark in the bottom right corner of
                    the video.
                  example: kie.ai
                callBackUrl:
                  type: string
                  description: >-
                    The URL to receive AI video generation task completion
                    updates. Required for all video generation requests.


                    - System will POST task status and results to this URL when
                    video generation completes

                    - Callback includes generated video URLs, cover images, and
                    task information

                    - Your callback endpoint should accept POST requests with
                    JSON payload containing video results

                    - For detailed callback format and implementation guide, see
                    [Video Generation
                    Callbacks](https://docs.kie.ai/runway-api/generate-ai-video-callbacks)

                    - Alternatively, use the Get AI Video Details endpoint to
                    poll task status

                    - To ensure callback security, see [Webhook Verification
                    Guide](/common-api/webhook-verification) for signature
                    verification implementation
                  example: https://api.example.com/callback
            example:
              prompt: >-
                A fluffy orange cat dancing energetically in a colorful room
                with disco lights
              imageUrl: https://example.com/cat-image.jpg
              model: runway-duration-5-generate
              waterMark: kie.ai
              callBackUrl: https://api.example.com/callback
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
                          failed validation checks.The request parameters are
                          incorrect, please check the parameters.

                          - **451**: Unauthorized - Failed to fetch the image.
                          Kindly verify any access limits set by you or your
                          service provider.

                          - **455**: Service Unavailable - System is currently
                          undergoing maintenance

                          - **500**: Server Error - An unexpected error occurred
                          while processing the request
                      msg:
                        type: string
                        description: Error message when code != 200
                        example: success
                  - type: object
                    properties:
                      data:
                        type: object
                        properties:
                          taskId:
                            type: string
                            description: >-
                              Unique identifier for the generation task, can be
                              used with `Get AI Video Details` to query task
                              status
                            example: ee603959-debb-48d1-98c4-a6d1c717eba6
              example:
                code: 200
                msg: success
                data:
                  taskId: ee603959-debb-48d1-98c4-a6d1c717eba6
        '500':
          $ref: '#/components/responses/Error'
      callbacks:
        onVideoGenerated:
          '{$request.body#/callBackUrl}':
            post:
              summary: Video Generation Completion Callback
              description: >-
                When video generation is complete, the system will send a POST
                request to the provided callback URL to notify the result
              requestBody:
                required: true
                content:
                  application/json:
                    schema:
                      type: object
                      required:
                        - code
                        - data
                        - msg
                      properties:
                        code:
                          type: integer
                          description: Status code, 200 indicates success
                          example: 200
                        msg:
                          type: string
                          description: Status message
                          example: All generated successfully.
                        data:
                          type: object
                          required:
                            - image_url
                            - task_id
                            - video_id
                            - video_url
                          properties:
                            image_url:
                              type: string
                              description: Cover image URL of the generated video
                              example: https://file.com/m/xxxxxxxx.png
                            task_id:
                              type: string
                              description: Task ID
                              example: ee603959-debb-48d1-98c4-a6d1c717eba6
                            video_id:
                              type: string
                              description: Video ID
                              example: 485da89c-7fca-4340-8c04-101025b2ae71
                            video_url:
                              type: string
                              description: Accessible video URL, valid for 14 days
                              example: https://file.com/k/xxxxxxx.mp4
              responses:
                '200':
                  description: Callback received successfully
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
                                  - 400
                                  - 500
                                description: >-
                                  Response status code


                                  - **200**: Success - Request has been
                                  processed successfully

                                  - **400**: get image info failed.

                                  Inappropriate content detected. Please replace
                                  the image or video.

                                  Incorrect image format.

                                  Please try again later. You can upgrade to
                                  Standard membership to start generating now.

                                  Reached the limit for concurrent generations.

                                  Unsupported width or height. Please adjust the
                                  size and try again.

                                  Upload failed due to network reasons, please
                                  re-enter.

                                  Your prompt was caught by our AI moderator. 
                                  Please adjust it and try again!

                                  Your prompt/negative prompt cannot exceed 2048
                                  characters. Please check if your input is too
                                  long.

                                  Your video creation prompt contains NSFW
                                  content, which isn't allowed under our policy.
                                  Kindly revise your prompt and generate again.

                                  - **500**: Server Error - An unexpected error
                                  occurred while processing the request
                              msg:
                                type: string
                                description: Error message when code != 200
                                example: success
                      example:
                        code: 200
                        msg: success
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