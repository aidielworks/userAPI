<?php

use App\Http\Requests\RegisterUserRequest;
use App\Models\User;
use App\Http\Requests\StoreUserRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Spatie\SimpleExcel\SimpleExcelReader;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::post('/login', function (Request $request) {
    $login = request()->validate([
        'email' => 'required|string',
        'password' => 'required|string',
    ]);
    if (!Auth::attempt($login)) {
        return response(['message' => 'Invalid credential']);
    }

    $user = Auth::user();
    $access_token = $user->createToken('authToken')->accessToken;
    
    return response(['user' => Auth::user(), compact('access_token')]);
});

Route::post('/register', function (RegisterUserRequest $request) {
    $request->validated();

    return User::create([
        'name' => request('name'),
        'email' => request('email'),
        'password' => Hash::make(request('password')),
    ]);
});

Route::group(['middleware' => 'auth:api','prefix' => 'users'], function () {
    //Create user
    Route::post('/create', function (StoreUserRequest $request) {
        $request->validated();

        return User::create([
            'name' => request('name'),
            'email' => request('email'),
            'password' => Hash::make('abcd1234'),
        ]);
    });

    //Read
    Route::get('/', function (Request $request) {
        $user = User::paginate(3);

        if (isset($request->name)) {
            $user = User::where('name', 'like', "%{$request->name}%")->paginate(
                3
            );
        }

        if (isset($request->email)) {
            $user = User::where(
                'email',
                'like',
                "%{$request->email}%"
            )->paginate(3);
        }

        if (isset($request->name) && isset($request->email)) {
            $user = User::where('email', 'like', "%{$request->email}%")
                ->where('name', 'like', "%{$request->name}%")
                ->paginate(3);
        }

        return new UserCollection($user);
    });

    //Update
    Route::patch('/{user}', function (Request $request, User $user) {
        $data = [
            'user' => new UserResource($user),
            'message' => 'User with id ' . $user->id . ' updated.',
        ];

        $request->validate([
            'name' => 'required|string',
            'email' =>
                $request->email == $user->email
                    ? 'required|string'
                    : 'required|string|unique:users',
        ]);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        return response($data, '200');
    });

    //Delete
    Route::post('/delete/{user}', function (User $user) {
        $data = [
            'user' => new UserResource($user),
            'message' => 'User with id ' . $user->id . ' deleted.',
        ];

        $user->delete();

        return response($data, '200');
    });

    Route::post('/import', function (Request $request) {

        $file = $request->file('spreadsheet');

        $file->move(public_path('spreadsheet'), $file->getClientOriginalName());

        $file_path = public_path() .'\\spreadsheet\\'. $file->getClientOriginalName();

        if ($request->import_type == 'create') {
            SimpleExcelReader::create($file_path)
                ->getRows()
                ->each(function (array $rowProperties) {
                    if(!empty($rowProperties['email'])){
                        $user = User::where('email', $rowProperties['email'])->first(); //check user exist or not
                        if (!$user) {
                            User::create([
                                'name' => $rowProperties['name'],
                                'email' => $rowProperties['email'],
                                'password' => Hash::make('abcd1234'),
                            ]);
                        }
                    }
                });
                
        } elseif ($request->import_type == 'update') {
            SimpleExcelReader::create($file_path)
                ->getRows()
                ->each(function (array $rowProperties) {
                    if(!empty($rowProperties['email'])){
                        $user = User::where('email', $rowProperties['email'])->first(); //check user exist or not
                        if ($user) {
                            $user->name = $rowProperties['name'];
                            $user->email = $rowProperties['email'];
                            $user->save();
                        }
                    }
                });

        } elseif ($request->import_type == 'delete') {
            SimpleExcelReader::create($file_path)
                ->getRows()
                ->each(function (array $rowProperties) {
                    $user = User::where('email', $rowProperties['email'])->first(); //check user exist or not
                    if ($user) {
                        $user->delete();
                    }
                });

        }
        
        if (file_exists($file_path)) {
            gc_collect_cycles();
            unlink($file_path);
        }

        return response('OK', '200');
    });
});
